<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../includes/boardle.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

$userId = (int) $_SESSION["user_id"];
$date   = boardleResolveDate($pdo, $_POST["date"] ?? null);

$board = filter_var($_POST["board"] ?? null, FILTER_VALIDATE_INT);
$hint  = trim($_POST["hint"] ?? "");

if ($board === false || $board < 0) {
    http_response_code(400);
    exit("Invalid board.");
}
if ($hint === "") {
    http_response_code(422);
    exit("Hint can't be empty.");
}

boardleEnsureDay($pdo, $date);
boardleFinalizeWords($pdo, $date);

// Load the day's boards (answers + current hint + chooser).
$wStmt = $pdo->prepare("SELECT position, word, chooser_id, hint FROM boardle_words WHERE game_date = ? ORDER BY position");
$wStmt->execute([$date]);
$answers = [];
$hints   = [];
foreach ($wStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $pos = (int) $r["position"];
    $answers[$pos] = $r["word"];
    $hints[$pos]   = ($r["hint"] !== null && $r["hint"] !== "") ? $r["hint"] : null;
}

if (!isset($answers[$board])) {
    http_response_code(400);
    exit("Invalid board.");
}

// Only a hintless board can receive a community hint — never overwrite the
// chooser's original clue (or another solver's).
if ($hints[$board] !== null) {
    http_response_code(409);
    exit("That board already has a hint.");
}

// The author must have SOLVED this board (their own pick counts as solved) —
// they know the word, so they can write a fair clue for the next players.
$owned = boardleOwnedPositions($pdo, $date, $userId);
$gStmt = $pdo->prepare("SELECT guess FROM boardle_guesses WHERE game_date = ? AND user_id = ? ORDER BY guess_index");
$gStmt->execute([$date, $userId]);
$myGuesses = $gStmt->fetchAll(PDO::FETCH_COLUMN);
$mb = boardleUserBoards($myGuesses, $answers, $owned);
if (empty($mb["solved_boards"][$board])) {
    http_response_code(403);
    exit("Solve this board before adding a hint.");
}

// Write it onto the shared board — visible to everyone from now on. The WHERE
// guard keeps a concurrent add from clobbering an already-written hint.
$pdo->prepare("UPDATE boardle_words SET hint = ? WHERE game_date = ? AND position = ? AND (hint IS NULL OR hint = '')")
    ->execute([$hint, $date, $board]);

header("Content-Type: application/json");
echo json_encode(boardleState($pdo, $date, $userId));
