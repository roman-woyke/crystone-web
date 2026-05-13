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

// Allowed fields and their validators
$allowedStatuses = ["PENDING", "REJECTED", "GHOSTED", "INTERVIEW", "OFFER"];
$allowedTags     = ["MAYBE", "PROBABLY", "FOR SURE", "ABSOLUTE CINEMA"];

$updates = [];
$params  = [];

// Status
if (isset($_POST["status"])) {
    $status = $_POST["status"];
    if (!in_array($status, $allowedStatuses, true)) {
        http_response_code(400);
        exit("Invalid status.");
    }
    $updates[] = "status = ?";
    $params[]  = $status;
}

// Tag
if (array_key_exists("tag", $_POST)) {
    $tag = $_POST["tag"] === "" ? null : $_POST["tag"];
    if ($tag !== null && !in_array($tag, $allowedTags, true)) {
        http_response_code(400);
        exit("Invalid tag.");
    }
    $updates[] = "tag = ?";
    $params[]  = $tag;
}

// Notes
if (array_key_exists("notes", $_POST)) {
    $notes     = trim($_POST["notes"]);
    $updates[] = "notes = ?";
    $params[]  = $notes === "" ? null : $notes;
}

// Job link
if (array_key_exists("job_link", $_POST)) {
    $jobLink   = trim($_POST["job_link"]);
    $updates[] = "job_link = ?";
    $params[]  = $jobLink === "" ? null : $jobLink;
}

if (count($updates) === 0) {
    http_response_code(400);
    exit("Nothing to update.");
}

// Append WHERE params — ownership check included
$params[] = $applicationId;
$params[] = $userId;

$stmt = $pdo->prepare("
    UPDATE applications
    SET " . implode(", ", $updates) . "
    WHERE id = ? AND user_id = ?
");

$stmt->execute($params);

// If status was updated, log it in history
if (isset($status)) {
    $historyStmt = $pdo->prepare("
        INSERT INTO application_status_history (user_id, application_id, status)
        VALUES (?, ?, ?)
    ");
    $historyStmt->execute([$userId, $applicationId, $status]);
}

http_response_code(200);
echo "OK";