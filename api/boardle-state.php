<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../includes/boardle.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

$date = boardleResolveDate($pdo, $_GET["date"] ?? null);

$payload = boardleState($pdo, $date, (int) $_SESSION["user_id"]);
$payload["today"]         = date("Y-m-d");
$payload["earliest_date"] = boardleEarliestDate($pdo);

header("Content-Type: application/json");
echo json_encode($payload);
