<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../includes/boardle-rt.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

$userId = (int) $_SESSION["user_id"];
$length = filter_var($_POST["length"] ?? null, FILTER_VALIDATE_INT);

boardleRtAdvance($pdo);
$row = boardleRtRow($pdo);
if ($row["phase"] !== "voting") {
    http_response_code(409);
    exit("Not in the voting phase.");
}

$choices = array_map("intval", explode(",", (string) $row["length_choices"]));
if ($length === false || !in_array($length, $choices, true)) {
    http_response_code(400);
    exit("Invalid length choice.");
}

$inRound = $pdo->prepare("SELECT 1 FROM boardle_rt_players WHERE round_id = ? AND user_id = ?");
$inRound->execute([(int) $row["round_id"], $userId]);
if (!$inRound->fetchColumn()) {
    http_response_code(403);
    exit("You're not in this round.");
}

$pdo->prepare("
    INSERT INTO boardle_rt_votes (round_id, user_id, length) VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE length = VALUES(length)
")->execute([(int) $row["round_id"], $userId, $length]);

header("Content-Type: application/json");
echo json_encode(boardleRtPayload($pdo, $userId));
