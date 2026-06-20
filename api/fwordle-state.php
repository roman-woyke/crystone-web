<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../includes/fwordle.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

header("Content-Type: application/json");
echo json_encode(fwordleState($pdo, date("Y-m-d"), (int) $_SESSION["user_id"]));
