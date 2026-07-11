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

// Late-pick: today has no guesses yet -> allow setting today's word, whether
// or not this player solved yesterday.
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

// For tomorrow's normal pick, respect the finalized lock.
// For today's late pick, we'll patch boardle_words directly after saving.
if (!$pickingForToday && (int) $tday["words_finalized"] === 1) {
    http_response_code(409);
    exit("Tomorrow's words are already locked in.");
}

$word = strtolower(trim($_POST["word"] ?? ""));
if (!boardleIsValidWord($word, $tlen)) {
    http_response_code(422);
    exit("Pick a valid $tlen-letter word.");
}

$hint = trim($_POST["hint"] ?? "");
if ($hint === "") $hint = null;

// Duplicates between players are allowed — rejecting a taken word would leak
// that someone else already picked it (spoiling their word).
$pdo->prepare("REPLACE INTO boardle_choices (game_date, user_id, word, hint) VALUES (?, ?, ?, ?)")
    ->execute([$targetDate, $userId, $word, $hint]);

// If today's words are already finalized, patch boardle_words directly so the
// live game sees the updated word before anyone guesses. Reuse a slot this
// user already owns (changing an earlier late pick), otherwise claim the
// lowest-numbered still-random (unclaimed) slot — this works whether or not
// the player solved yesterday, since a random slot exists precisely when
// nobody has claimed it yet.
if ($pickingForToday && (int) $tday["words_finalized"] === 1) {
    $slotsStmt = $pdo->prepare("SELECT position, source, chooser_id FROM boardle_words WHERE game_date = ? ORDER BY position");
    $slotsStmt->execute([$date]);
    $slots = $slotsStmt->fetchAll(PDO::FETCH_ASSOC);

    $targetPos = null;
    foreach ($slots as $s) {
        if ($s['chooser_id'] !== null && (int) $s['chooser_id'] === $userId) { $targetPos = (int) $s['position']; break; }
    }
    if ($targetPos === null) {
        foreach ($slots as $s) {
            if ($s['source'] === 'random') { $targetPos = (int) $s['position']; break; }
        }
    }
    if ($targetPos !== null) {
        $pdo->prepare("UPDATE boardle_words SET word = ?, hint = ?, source = 'chosen', chooser_id = ? WHERE game_date = ? AND position = ?")
            ->execute([$word, $hint, $userId, $date, $targetPos]);
    }
}

header("Content-Type: application/json");
echo json_encode(["ok" => true, "word" => $word, "hint" => $hint, "tomorrow_length" => $tlen]);
