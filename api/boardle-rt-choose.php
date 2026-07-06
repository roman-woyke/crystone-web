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
$hint   = mb_substr(trim($_POST["hint"] ?? ""), 0, 500);

boardleRtAdvance($pdo);
$row = boardleRtRow($pdo);
if ($row["phase"] !== "selecting") {
    http_response_code(409);
    exit("Not in the word-selection phase.");
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

if ($hint === "") {
    http_response_code(422);
    exit("A hint is mandatory.");
}
if (strlen($word) !== $length || !boardleIsValidWord($word, $length)) {
    http_response_code(422);
    exit("Not a valid $length-letter word.");
}

$existing = $pdo->prepare("SELECT 1 FROM boardle_rt_words WHERE round_id = ? AND position = ?");
$existing->execute([$roundId, (int) $position]);
if ($existing->fetchColumn()) {
    http_response_code(409);
    exit("You've already chosen your word.");
}

try {
    $pdo->prepare("
        INSERT INTO boardle_rt_words (round_id, position, chooser_id, word, hint) VALUES (?, ?, ?, ?, ?)
    ")->execute([$roundId, (int) $position, $userId, $word, $hint]);
} catch (Throwable $e) {
    http_response_code(409);
    exit("Word conflict — reload and try again.");
}

header("Content-Type: application/json");
echo json_encode(boardleRtPayload($pdo, $userId));
