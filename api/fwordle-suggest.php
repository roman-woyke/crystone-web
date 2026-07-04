<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../includes/fwordle.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

$userId    = (int) $_SESSION["user_id"];
$date      = date("Y-m-d");
$tomorrow  = date("Y-m-d", strtotime($date . " +1 day"));

// Mirror fwordle-choose.php's eligibility so the length matches the day the
// picker is actually setting a word for.
$pickingForToday = false;
if (!fwordleChoiceEligible($pdo, $date, $userId)) {
    $gStmt = $pdo->prepare("SELECT COUNT(*) FROM fwordle_guesses WHERE game_date = ?");
    $gStmt->execute([$date]);
    if ((int) $gStmt->fetchColumn() === 0) {
        $pickingForToday = true;
    }
}

if (!$pickingForToday && !fwordleChoiceEligible($pdo, $date, $userId)) {
    http_response_code(403);
    exit("Solve today's fWordle first to set a word.");
}

$targetDate = $pickingForToday ? $date : $tomorrow;
$tday = fwordleEnsureDay($pdo, $targetDate);
$tlen = (int) $tday["word_length"];

header("Content-Type: application/json");
echo json_encode([
    "suggestions"     => fwordleRandomSuggestions($tlen, 3),
    "tomorrow_length" => $tlen,
]);
