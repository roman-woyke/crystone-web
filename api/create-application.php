<?php

require_once __DIR__ . "/../includes/start-session.php";

require_once __DIR__ . "/../../config.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: /ben/login.php");
    exit;
}

$userId = $_SESSION["user_id"];

$companyName = trim($_POST["company_name"] ?? "");
$jobTitle    = trim($_POST["job_title"]    ?? "");
$jobLink     = trim($_POST["job_link"]     ?? "");
$location    = trim($_POST["location"]     ?? "");
$notes       = trim($_POST["notes"]        ?? "");
$tag         = trim($_POST["tag"]          ?? "");

if ($companyName === "") {
    die("Company name is required.");
}

$jobTitle  = $jobTitle  === "" ? null : $jobTitle;
$jobLink   = $jobLink   === "" ? null : $jobLink;
$location  = $location  === "" ? null : $location;
$notes     = $notes     === "" ? null : $notes;

$allowedTags = ["MAYBE", "PROBABLY", "FOR SURE", "ABSOLUTE CINEMA"];
$tag         = in_array($tag, $allowedTags, true) ? $tag : null;

// Insert the application
$stmt = $pdo->prepare("
    INSERT INTO applications (
        user_id,
        company_name,
        job_title,
        job_link,
        location,
        notes,
        tag,
        status
    )
    VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDING')
");

$stmt->execute([
    $userId,
    $companyName,
    $jobTitle,
    $jobLink,
    $location,
    $notes,
    $tag
]);

$newApplicationId = $pdo->lastInsertId();

// Log the initial PENDING status in history
$historyStmt = $pdo->prepare("
    INSERT INTO application_status_history (user_id, application_id, status)
    VALUES (?, ?, 'PENDING')
");

$historyStmt->execute([$userId, $newApplicationId]);

header("Location: /ben/dashboard.php");
exit;