<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

$userId = $_SESSION["user_id"];
$id     = filter_var($_POST["id"] ?? null, FILTER_VALIDATE_INT);

if ($id === false || $id === null || $id <= 0) {
    http_response_code(400);
    exit("Invalid session id.");
}

// Ownership check in the WHERE clause — you can only delete your own sessions.
// Attached study_segments are removed by ON DELETE CASCADE.
$stmt = $pdo->prepare("DELETE FROM study_sessions WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $userId]);

if ($stmt->rowCount() === 0) {
    http_response_code(404);
    exit("Session not found.");
}

echo "OK";
