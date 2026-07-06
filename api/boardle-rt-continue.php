<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../includes/boardle-rt.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

boardleRtAdvance($pdo);
$row = boardleRtRow($pdo);
if ($row["phase"] === "results") {
    $pdo->prepare("UPDATE boardle_rt_state SET phase = 'lobby', phase_started_at = NOW() WHERE id = 1")->execute();
}

header("Content-Type: application/json");
echo json_encode(boardleRtPayload($pdo, (int) $_SESSION["user_id"]));
