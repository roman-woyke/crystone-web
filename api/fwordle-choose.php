<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../../config.php";
require_once __DIR__ . "/../includes/fwordle.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

$userId   = (int) $_SESSION["user_id"];
$date     = date("Y-m-d");
$tomorrow = date("Y-m-d", strtotime($date . " +1 day"));

// Only the first ≤4 solvers of today earn a word pick.
if (!fwordleChoiceEligible($pdo, $date, $userId)) {
    http_response_code(403);
    exit("Solve today's fWordle first to set a word.");
}

$tday = fwordleEnsureDay($pdo, $tomorrow);
$tlen = (int) $tday["word_length"];

if ((int) $tday["words_finalized"] === 1) {
    http_response_code(409);
    exit("Tomorrow's words are already locked in.");
}

$word = strtolower(trim($_POST["word"] ?? ""));
if (!fwordleIsValidWord($word, $tlen)) {
    http_response_code(422);
    exit("Pick a valid $tlen-letter word.");
}

// No two boards share a word.
$dup = $pdo->prepare("SELECT 1 FROM fwordle_choices WHERE game_date = ? AND word = ? AND user_id <> ?");
$dup->execute([$tomorrow, $word, $userId]);
if ($dup->fetchColumn()) {
    http_response_code(409);
    exit("Someone already picked that word — choose another.");
}

$pdo->prepare("REPLACE INTO fwordle_choices (game_date, user_id, word) VALUES (?, ?, ?)")
    ->execute([$tomorrow, $userId, $word]);

header("Content-Type: application/json");
echo json_encode(["ok" => true, "word" => $word, "tomorrow_length" => $tlen]);
