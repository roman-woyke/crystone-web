<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

$userId        = $_SESSION["user_id"];
$applicationId = $_POST["application_id"] ?? "";

if ($applicationId === "") {
    http_response_code(400);
    exit("Missing application ID.");
}

// Delete history first (foreign key safety)
$historyStmt = $pdo->prepare("
    DELETE FROM application_status_history
    WHERE application_id = ? AND user_id = ?
");
$historyStmt->execute([$applicationId, $userId]);

// Delete the application — ownership check included
$stmt = $pdo->prepare("
    DELETE FROM applications
    WHERE id = ? AND user_id = ?
");
$stmt->execute([$applicationId, $userId]);

if ($stmt->rowCount() === 0) {
    http_response_code(404);
    exit("Application not found.");
}

http_response_code(200);
echo "OK";