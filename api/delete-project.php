<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../../config.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

$userId    = $_SESSION["user_id"];
$projectId = (int) ($_POST["project_id"] ?? 0);

if ($projectId <= 0) {
    http_response_code(400);
    exit("Missing project ID.");
}

// Delete time entries first (foreign key safety), scoped to owner
$entryStmt = $pdo->prepare("
    DELETE FROM project_time_entries
    WHERE project_id = ? AND user_id = ?
");
$entryStmt->execute([$projectId, $userId]);

// Delete the project — ownership check included
$stmt = $pdo->prepare("DELETE FROM projects WHERE id = ? AND user_id = ?");
$stmt->execute([$projectId, $userId]);

if ($stmt->rowCount() === 0) {
    http_response_code(404);
    exit("Project not found.");
}

http_response_code(200);
echo "OK";
