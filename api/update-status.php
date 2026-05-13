<?php

require_once __DIR__ . "/../includes/start-session.php";

require_once __DIR__ . "/../../config.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

$userId = $_SESSION["user_id"];

$applicationId = $_POST["application_id"] ?? "";
$newStatus     = $_POST["status"]         ?? "";

$allowedStatuses = [
    "PENDING",
    "REJECTED",
    "GHOSTED",
    "INTERVIEW",
    "OFFER"
];

if ($applicationId === "" || !in_array($newStatus, $allowedStatuses, true)) {
    http_response_code(400);
    exit("Invalid request.");
}

// Update the application status (ownership check included)
$stmt = $pdo->prepare("
    UPDATE applications
    SET status = ?
    WHERE id = ? AND user_id = ?
");

$stmt->execute([$newStatus, $applicationId, $userId]);

// Only log if the update actually affected a row (ownership verified)
if ($stmt->rowCount() > 0) {
    $historyStmt = $pdo->prepare("
        INSERT INTO application_status_history (user_id, application_id, status)
        VALUES (?, ?, ?)
    ");

    $historyStmt->execute([$userId, $applicationId, $newStatus]);
}

header("Location: /ben/dashboard.php#app-" . urlencode($applicationId));
exit;