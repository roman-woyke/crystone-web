<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../includes/fwordle.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

$userId = (int) $_SESSION["user_id"];
$date   = date("Y-m-d");

$type  = $_POST["type"] ?? "";
$board = filter_var($_POST["board"] ?? null, FILTER_VALIDATE_INT);

if (!in_array($type, ["armor", "orange", "green"], true)) {
    http_response_code(400);
    exit("Bad joker type.");
}

fwordleEnsureDay($pdo, $date);
fwordleFinalizeWords($pdo, $date);

// Done for the day? No more jokers.
$rStmt = $pdo->prepare("SELECT finished FROM fwordle_results WHERE game_date = ? AND user_id = ?");
$rStmt->execute([$date, $userId]);
if ((int) $rStmt->fetchColumn() === 1) {
    http_response_code(409);
    exit("You're already done for today.");
}

// Each joker is once per day.
$hStmt = $pdo->prepare("SELECT type FROM fwordle_hints WHERE game_date = ? AND user_id = ? AND type = ?");
$hStmt->execute([$date, $userId, $type]);
if ($hStmt->fetchColumn() !== false) {
    http_response_code(409);
    exit("You've already used your $type joker.");
}

// Payment: a joker costs 1 streak, but a player with no streak gets one free per
// day (their first), and a freeze can be exchanged for a joker once a day. The
// client may request `pay=freeze`; we also fall back to a freeze when there's no
// streak left to spend. `$viaFreeze` records which.
$info = fwordleStreakInfo($pdo, $date, $userId);
$usedStmt = $pdo->prepare("SELECT COUNT(*) FROM fwordle_hints WHERE game_date = ? AND user_id = ?");
$usedStmt->execute([$date, $userId]);
$usedToday = (int) $usedStmt->fetchColumn();

$wantFreeze = (($_POST["pay"] ?? "") === "freeze");
$freeJoker  = ($info['effective'] < 1 && $usedToday === 0);
$streakOk   = ($info['effective'] >= 1 || $freeJoker);
$freezeOk   = ($info['freezes'] >= 1 && !$info['freeze_used_today']);

$viaFreeze = 0;
if ($wantFreeze) {
    if (!$freezeOk) {
        http_response_code(409);
        exit("No freeze to exchange today.");
    }
    $viaFreeze = 1;
} elseif ($streakOk) {
    $viaFreeze = 0;
} elseif ($freezeOk) {
    $viaFreeze = 1; // out of streak → auto-pay with a freeze
} else {
    http_response_code(409);
    exit("Not enough streak or freezes to spend a joker.");
}

// ── Armor: +1 guess overall, no board, no reveal. ──
if ($type === "armor") {
    try {
        $pdo->prepare("
            INSERT INTO fwordle_hints (game_date, user_id, board_pos, type, payload, via_freeze)
            VALUES (?, ?, NULL, 'armor', '1', ?)
        ")->execute([$date, $userId, $viaFreeze]);
    } catch (Throwable $e) {
        http_response_code(409);
        exit("Joker conflict — reload and try again.");
    }
    header("Content-Type: application/json");
    echo json_encode(fwordleState($pdo, $date, $userId));
    exit;
}

// ── Board jokers (orange / green) ──
$aStmt = $pdo->prepare("SELECT position, word, chooser_id FROM fwordle_words WHERE game_date = ? ORDER BY position");
$aStmt->execute([$date]);
$answers = [];
$chooser = [];
foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $answers[(int) $r["position"]] = $r["word"];
    $chooser[(int) $r["position"]] = $r["chooser_id"] !== null ? (int) $r["chooser_id"] : null;
}

if ($board === false || !isset($answers[$board])) {
    http_response_code(400);
    exit("Invalid board.");
}

// Can't hint a board you already solved (your own pick is auto-solved too).
if ($chooser[$board] === $userId) {
    http_response_code(409);
    exit("That's your own board.");
}

// My guesses so far (to avoid revealing anything I already know).
$gStmt = $pdo->prepare("SELECT guess FROM fwordle_guesses WHERE game_date = ? AND user_id = ? ORDER BY guess_index");
$gStmt->execute([$date, $userId]);
$myGuesses = $gStmt->fetchAll(PDO::FETCH_COLUMN);

$payload = fwordleComputeHint($answers[$board], $myGuesses, $type);
if ($payload === null) {
    http_response_code(422);
    exit("Nothing new to reveal there.");
}

try {
    $pdo->prepare("
        INSERT INTO fwordle_hints (game_date, user_id, board_pos, type, payload, via_freeze)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$date, $userId, $board, $type, $payload, $viaFreeze]);
} catch (Throwable $e) {
    http_response_code(409);
    exit("Joker conflict — reload and try again.");
}

header("Content-Type: application/json");
echo json_encode(fwordleState($pdo, $date, $userId));
