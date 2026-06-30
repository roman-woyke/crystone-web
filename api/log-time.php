<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

$userId    = $_SESSION["user_id"];
$projectId = (int) ($_POST["project_id"] ?? 0);
$seconds   = (int) ($_POST["seconds"]    ?? 0);
$note      = trim($_POST["note"] ?? "");

if ($projectId <= 0) {
    http_response_code(400);
    exit("Missing project ID.");
}

if ($seconds <= 0) {
    http_response_code(400);
    exit("Duration must be greater than zero.");
}

// Cap absurd values (e.g. a forgotten running timer) at 24h
if ($seconds > 86400) {
    $seconds = 86400;
}

$note = $note === "" ? null : mb_substr($note, 0, 255);

// Ownership check: project must belong to the logged-in user
$check = $pdo->prepare("SELECT id FROM projects WHERE id = ? AND user_id = ?");
$check->execute([$projectId, $userId]);
if (!$check->fetch()) {
    http_response_code(403);
    exit("Project not found.");
}

$stmt = $pdo->prepare("
    INSERT INTO project_time_entries (project_id, user_id, seconds, note)
    VALUES (?, ?, ?, ?)
");
$stmt->execute([$projectId, $userId, $seconds, $note]);

http_response_code(200);
echo "OK";
