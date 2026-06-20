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

fwordleEnsureDay($pdo, $date);
fwordleFinalizeWords($pdo, $date);

$lenStmt = $pdo->prepare("SELECT word_length FROM fwordle_days WHERE game_date = ?");
$lenStmt->execute([$date]);
$len = (int) $lenStmt->fetchColumn();

$numBoards  = (int) $pdo->query("SELECT COUNT(*) FROM fwordle_words WHERE game_date = " . $pdo->quote($date))->fetchColumn();
$maxGuesses = fwordleMaxGuesses($numBoards, $len);

// Already finished today? No more guesses.
$rStmt = $pdo->prepare("SELECT finished, solved, solved_at FROM fwordle_results WHERE game_date = ? AND user_id = ?");
$rStmt->execute([$date, $userId]);
$result = $rStmt->fetch(PDO::FETCH_ASSOC);
if ($result && (int) $result["finished"] === 1) {
    http_response_code(409);
    exit("You're already done for today.");
}

// How many guesses have I made?
$cStmt = $pdo->prepare("SELECT COUNT(*) FROM fwordle_guesses WHERE game_date = ? AND user_id = ?");
$cStmt->execute([$date, $userId]);
$used = (int) $cStmt->fetchColumn();
if ($used >= $maxGuesses) {
    http_response_code(409);
    exit("No guesses left.");
}

// ── Validate the submitted word + position ──
$word   = strtolower(trim($_POST["word"] ?? ""));
$offset = filter_var($_POST["offset"] ?? null, FILTER_VALIDATE_INT);
$wl     = strlen($word);

if (!preg_match('/^[a-z]+$/', $word) || $wl < FWORDLE_MIN_LEN || $wl > $len) {
    http_response_code(400);
    exit("A guess must be a word of " . FWORDLE_MIN_LEN . "–$len letters.");
}
if ($offset === false || $offset < 0 || $offset + $wl > $len) {
    http_response_code(400);
    exit("Invalid word position.");
}
if (!fwordleIsValidWord($word, $wl)) {
    http_response_code(422);
    exit("Not in word list.");
}

// Encode the placement as an L-wide string ('_' = blank cell).
$guess = str_repeat("_", $offset) . $word . str_repeat("_", $len - $offset - $wl);

// Append the guess (race-safe via the unique (date,user,index) key).
$ins = $pdo->prepare("
    INSERT INTO fwordle_guesses (game_date, user_id, guess_index, guess)
    VALUES (?, ?, ?, ?)
");
try {
    $ins->execute([$date, $userId, $used, $guess]);
} catch (Throwable $e) {
    http_response_code(409);
    exit("Guess conflict — reload and try again.");
}

// Recompute my standing across every board.
$aStmt = $pdo->prepare("SELECT word FROM fwordle_words WHERE game_date = ? ORDER BY position");
$aStmt->execute([$date]);
$answers = $aStmt->fetchAll(PDO::FETCH_COLUMN);

$gStmt = $pdo->prepare("SELECT guess FROM fwordle_guesses WHERE game_date = ? AND user_id = ? ORDER BY guess_index");
$gStmt->execute([$date, $userId]);
$myGuesses = $gStmt->fetchAll(PDO::FETCH_COLUMN);

$owned     = fwordleOwnedPositions($pdo, $date, $userId);
$boards    = fwordleUserBoards($myGuesses, $answers, $owned);
$usedNow   = count($myGuesses);
$solved    = $boards["solved"];
$finished  = $solved || $usedNow >= $maxGuesses;

// Preserve an existing solve time; stamp it the moment all boards fall.
$solvedAt = ($result && $result["solved_at"]) ? $result["solved_at"] : null;
if ($solved && $solvedAt === null) $solvedAt = date("Y-m-d H:i:s");

$pdo->prepare("
    INSERT INTO fwordle_results (game_date, user_id, finished, solved, guesses_used, solved_at)
    VALUES (?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        finished = VALUES(finished),
        solved = VALUES(solved),
        guesses_used = VALUES(guesses_used),
        solved_at = VALUES(solved_at)
")->execute([$date, $userId, $finished ? 1 : 0, $solved ? 1 : 0, $usedNow, $solvedAt]);

header("Content-Type: application/json");
echo json_encode(fwordleState($pdo, $date, $userId));
