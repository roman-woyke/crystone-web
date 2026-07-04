<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../includes/boardle.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

$userId    = (int) $_SESSION["user_id"];
$date      = date("Y-m-d");
$tomorrow  = date("Y-m-d", strtotime($date . " +1 day"));

// Mirror boardle-choose.php's eligibility so the length matches the day the
// picker is actually setting a word for.
$pickingForToday = false;
if (!boardleChoiceEligible($pdo, $date, $userId)) {
    $gStmt = $pdo->prepare("SELECT COUNT(*) FROM boardle_guesses WHERE game_date = ?");
    $gStmt->execute([$date]);
    if ((int) $gStmt->fetchColumn() === 0) {
        $pickingForToday = true;
    }
}

if (!$pickingForToday && !boardleChoiceEligible($pdo, $date, $userId)) {
    http_response_code(403);
    exit("Solve today's Boardle first to set a word.");
}

$targetDate = $pickingForToday ? $date : $tomorrow;
$tday = boardleEnsureDay($pdo, $targetDate);
$tlen = (int) $tday["word_length"];

header("Content-Type: application/json");
echo json_encode([
    "suggestions"     => boardleRandomSuggestions($tlen, 3),
    "tomorrow_length" => $tlen,
]);
