<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../includes/boardle-rt.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

$userId = (int) $_SESSION["user_id"];
$ready  = filter_var($_POST["ready"] ?? "1", FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

boardleRtAdvance($pdo);
$row = boardleRtRow($pdo);
if ($row["phase"] !== "lobby") {
    http_response_code(409);
    exit("A round is already in progress.");
}

$pdo->prepare("
    INSERT INTO boardle_rt_ready (user_id, ready) VALUES (?, ?)
    ON DUPLICATE KEY UPDATE ready = VALUES(ready), updated_at = NOW()
")->execute([$userId, $ready]);

header("Content-Type: application/json");
echo json_encode(boardleRtPayload($pdo, $userId));
