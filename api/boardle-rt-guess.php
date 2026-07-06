<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../includes/boardle-rt.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

$userId = (int) $_SESSION["user_id"];
$word   = strtolower(trim($_POST["word"] ?? ""));
$offset = filter_var($_POST["offset"] ?? null, FILTER_VALIDATE_INT);

boardleRtAdvance($pdo);
$row = boardleRtRow($pdo);
if ($row["phase"] !== "playing") {
    http_response_code(409);
    exit("Not in the playing phase.");
}

$roundId = (int) $row["round_id"];
$length  = (int) $row["word_length"];

$pStmt = $pdo->prepare("SELECT position FROM boardle_rt_players WHERE round_id = ? AND user_id = ?");
$pStmt->execute([$roundId, $userId]);
$position = $pStmt->fetchColumn();
if ($position === false) {
    http_response_code(403);
    exit("You're not in this round.");
}
$position = (int) $position;

$wStmt = $pdo->prepare("SELECT position, word FROM boardle_rt_words WHERE round_id = ? ORDER BY position");
$wStmt->execute([$roundId]);
$answers = [];
foreach ($wStmt->fetchAll(PDO::FETCH_ASSOC) as $r) $answers[(int) $r["position"]] = $r["word"];
$numBoards  = count($answers);
$maxGuesses = boardleMaxGuesses($numBoards, $length);

$rStmt = $pdo->prepare("SELECT finished FROM boardle_rt_results WHERE round_id = ? AND user_id = ?");
$rStmt->execute([$roundId, $userId]);
if ((int) $rStmt->fetchColumn() === 1) {
    http_response_code(409);
    exit("You're already done with this round.");
}

$cStmt = $pdo->prepare("SELECT COUNT(*) FROM boardle_rt_guesses WHERE round_id = ? AND user_id = ?");
$cStmt->execute([$roundId, $userId]);
$used = (int) $cStmt->fetchColumn();
if ($used >= $maxGuesses) {
    http_response_code(409);
    exit("No guesses left.");
}

// Same rules as daily Boardle: any valid word from 5 letters up to the
// round's length, positioned within the row (space bar shifts it), encoded
// as an L-wide string with '_' for blank cells.
$wl = strlen($word);
if (!preg_match('/^[a-z]+$/', $word) || $wl < BOARDLE_MIN_LEN || $wl > $length) {
    http_response_code(400);
    exit("A guess must be a word of " . BOARDLE_MIN_LEN . "-$length letters.");
}
if ($offset === false || $offset < 0 || $offset + $wl > $length) {
    http_response_code(400);
    exit("Invalid word position.");
}
if (!boardleIsValidWord($word, $wl)) {
    http_response_code(422);
    exit("Not in word list.");
}

$guess = str_repeat("_", $offset) . $word . str_repeat("_", $length - $offset - $wl);

try {
    $pdo->prepare("
        INSERT INTO boardle_rt_guesses (round_id, user_id, guess_index, guess) VALUES (?, ?, ?, ?)
    ")->execute([$roundId, $userId, $used, $guess]);
} catch (Throwable $e) {
    http_response_code(409);
    exit("Guess conflict — reload and try again.");
}

$gStmt = $pdo->prepare("SELECT guess FROM boardle_rt_guesses WHERE round_id = ? AND user_id = ? ORDER BY guess_index");
$gStmt->execute([$roundId, $userId]);
$myGuesses = $gStmt->fetchAll(PDO::FETCH_COLUMN);

$boards   = boardleUserBoards($myGuesses, $answers, [$position]);
$usedNow  = count($myGuesses);
$solved   = $boards["solved"];
$finished = $solved || $usedNow >= $maxGuesses;

$rStmt2 = $pdo->prepare("SELECT solved_at FROM boardle_rt_results WHERE round_id = ? AND user_id = ?");
$rStmt2->execute([$roundId, $userId]);
$existingSolvedAt = $rStmt2->fetchColumn();
$solvedAt = $existingSolvedAt ?: ($solved ? date("Y-m-d H:i:s") : null);

$pdo->prepare("
    INSERT INTO boardle_rt_results (round_id, user_id, finished, solved, guesses_used, solved_at)
    VALUES (?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        finished = VALUES(finished),
        solved = VALUES(solved),
        guesses_used = VALUES(guesses_used),
        solved_at = VALUES(solved_at)
")->execute([$roundId, $userId, $finished ? 1 : 0, $solved ? 1 : 0, $usedNow, $solvedAt]);

header("Content-Type: application/json");
echo json_encode(boardleRtPayload($pdo, $userId));
