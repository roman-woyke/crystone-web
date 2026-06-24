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

if (!in_array($type, ["grey", "orange", "green"], true)) {
    http_response_code(400);
    exit("Bad hint type.");
}

fwordleEnsureDay($pdo, $date);
fwordleFinalizeWords($pdo, $date);

// Answers for today (board positions).
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

// Done for the day? No more hints.
$rStmt = $pdo->prepare("SELECT finished FROM fwordle_results WHERE game_date = ? AND user_id = ?");
$rStmt->execute([$date, $userId]);
if ((int) $rStmt->fetchColumn() === 1) {
    http_response_code(409);
    exit("You're already done for today.");
}

// One joker per board, and each type only once.
$hStmt = $pdo->prepare("SELECT board_pos, type FROM fwordle_hints WHERE game_date = ? AND user_id = ?");
$hStmt->execute([$date, $userId]);
foreach ($hStmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
    if ((int) $h["board_pos"] === $board) {
        http_response_code(409);
        exit("This board already has a hint.");
    }
    if ($h["type"] === $type) {
        http_response_code(409);
        exit("You've already used your $type hint.");
    }
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
        INSERT INTO fwordle_hints (game_date, user_id, board_pos, type, payload)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$date, $userId, $board, $type, $payload]);
} catch (Throwable $e) {
    http_response_code(409);
    exit("Hint conflict — reload and try again.");
}

header("Content-Type: application/json");
echo json_encode(fwordleState($pdo, $date, $userId));
