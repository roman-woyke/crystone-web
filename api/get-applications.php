<?php

require_once __DIR__ . "/../includes/start-session.php";

require_once __DIR__ . "/../../config.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

$userId = $_SESSION["user_id"];

$stmt = $pdo->prepare("
    SELECT id, company_name, job_title, job_link, location, status, notes, created_at
    FROM applications
    WHERE user_id = ?
    ORDER BY created_at DESC
");

$stmt->execute([$userId]);

$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

header("Content-Type: application/json");
echo json_encode($applications);