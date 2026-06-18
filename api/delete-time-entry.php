<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../../config.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

$userId  = $_SESSION["user_id"];
$entryId = (int) ($_POST["entry_id"] ?? 0);

if ($entryId <= 0) {
    http_response_code(400);
    exit("Missing entry ID.");
}

// Ownership check included
$stmt = $pdo->prepare("DELETE FROM project_time_entries WHERE id = ? AND user_id = ?");
$stmt->execute([$entryId, $userId]);

if ($stmt->rowCount() === 0) {
    http_response_code(404);
    exit("Entry not found.");
}

http_response_code(200);
echo "OK";
