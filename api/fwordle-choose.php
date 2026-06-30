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
$yesterday = date("Y-m-d", strtotime($date . " -1 day"));

// Late-pick: solved yesterday, today has no guesses yet -> allow setting today's word.
$pickingForToday = false;
if (!fwordleChoiceEligible($pdo, $date, $userId) && fwordleChoiceEligible($pdo, $yesterday, $userId)) {
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

// For tomorrow's normal pick, respect the finalized lock.
// For today's late pick, we'll patch fwordle_words directly after saving.
if (!$pickingForToday && (int) $tday["words_finalized"] === 1) {
    http_response_code(409);
    exit("Tomorrow's words are already locked in.");
}

$word = strtolower(trim($_POST["word"] ?? ""));
if (!fwordleIsValidWord($word, $tlen)) {
    http_response_code(422);
    exit("Pick a valid $tlen-letter word.");
}

$hint = mb_substr(trim($_POST["hint"] ?? ""), 0, 500);
if ($hint === "") $hint = null;

// Duplicates between players are allowed — rejecting a taken word would leak
// that someone else already picked it (spoiling their word).
$pdo->prepare("REPLACE INTO fwordle_choices (game_date, user_id, word, hint) VALUES (?, ?, ?, ?)")
    ->execute([$targetDate, $userId, $word, $hint]);

// If today's words are already finalized, patch fwordle_words directly so the
// live game sees the updated word before anyone guesses.
// We look up the board position by player rank (not chooser_id) because the
// slot may have been filled with a random backfill (chooser_id = NULL) if the
// user hadn't picked a word before the day started.
if ($pickingForToday && (int) $tday["words_finalized"] === 1) {
    $solverStmt = $pdo->prepare("
        SELECT r.user_id, u.username FROM fwordle_results r
        JOIN users u ON u.id = r.user_id
        WHERE r.game_date = ? AND r.solved = 1
        ORDER BY r.solved_at ASC
        LIMIT " . FWORDLE_MAX_WORDS
    );
    $solverStmt->execute([$yesterday]);
    $solverRows = $solverStmt->fetchAll(PDO::FETCH_ASSOC);
    usort($solverRows, fn($a, $b) => fwordlePlayerRank($a['username']) <=> fwordlePlayerRank($b['username']));
    $userPos = null;
    foreach ($solverRows as $idx => $row) {
        if ((int) $row['user_id'] === $userId) { $userPos = $idx; break; }
    }
    if ($userPos !== null) {
        $pdo->prepare("UPDATE fwordle_words SET word = ?, hint = ?, source = 'chosen', chooser_id = ? WHERE game_date = ? AND position = ?")
            ->execute([$word, $hint, $userId, $date, $userPos]);
    }
}

header("Content-Type: application/json");
echo json_encode(["ok" => true, "word" => $word, "hint" => $hint, "tomorrow_length" => $tlen]);
