<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

$userId = $_SESSION["user_id"];

// The client sends raw counters only — WPM and accuracy are computed here so
// a tampered request cannot claim an arbitrary score.
$correctChars = filter_var($_POST["correct_chars"] ?? null, FILTER_VALIDATE_INT);
$typedChars   = filter_var($_POST["typed_chars"]   ?? null, FILTER_VALIDATE_INT);
$durationMs   = filter_var($_POST["duration_ms"]   ?? null, FILTER_VALIDATE_INT);

if ($correctChars === false || $typedChars === false || $durationMs === false) {
    http_response_code(400);
    exit("Missing or invalid fields.");
}

// Sanity bounds: fixed 60-second round (small clock tolerance), humanly
// possible character counts, and correct chars can never exceed typed chars.
if ($correctChars < 0 || $typedChars <= 0 || $correctChars > $typedChars) {
    http_response_code(400);
    exit("Invalid character counts.");
}

if ($typedChars > 2500) {
    http_response_code(400);
    exit("Invalid character counts.");
}

if ($durationMs < 55000 || $durationMs > 65000) {
    http_response_code(400);
    exit("Invalid duration.");
}

$wpm      = ($correctChars / 5) / ($durationMs / 60000);
$accuracy = $correctChars / $typedChars * 100;

if ($wpm > 220) {
    http_response_code(400);
    exit("Implausible result.");
}

$wpm      = round($wpm, 2);
$accuracy = round($accuracy, 2);

$bestStmt = $pdo->prepare("SELECT MAX(wpm) FROM typing_scores WHERE user_id = ?");
$bestStmt->execute([$userId]);
$previousBest = $bestStmt->fetchColumn();

$personalBest = ($previousBest === null || $wpm > (float) $previousBest);

$stmt = $pdo->prepare("
    INSERT INTO typing_scores (user_id, wpm, accuracy, correct_chars, typed_chars, duration_ms)
    VALUES (?, ?, ?, ?, ?, ?)
");

$stmt->execute([$userId, $wpm, $accuracy, $correctChars, $typedChars, $durationMs]);

header("Content-Type: application/json");
echo json_encode([
    "wpm"           => $wpm,
    "accuracy"      => $accuracy,
    "personal_best" => $personalBest,
]);
