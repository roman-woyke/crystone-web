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

$updates = [];
$params  = [];

if (array_key_exists("name", $_POST)) {
    $name = trim($_POST["name"]);
    if ($name === "") {
        http_response_code(400);
        exit("Name cannot be empty.");
    }
    $updates[] = "name = ?";
    $params[]  = mb_substr($name, 0, 255);
}

if (array_key_exists("description", $_POST)) {
    $description = trim($_POST["description"]);
    $updates[]   = "description = ?";
    $params[]    = $description === "" ? null : $description;
}

if (array_key_exists("color", $_POST)) {
    $palette = ["#2563eb", "#16a34a", "#9333ea", "#ea580c", "#db2777", "#0d9488", "#dc2626", "#ca8a04"];
    $color   = $_POST["color"];
    if (!in_array($color, $palette, true)) {
        http_response_code(400);
        exit("Invalid color.");
    }
    $updates[] = "color = ?";
    $params[]  = $color;
}

if (count($updates) === 0) {
    http_response_code(400);
    exit("Nothing to update.");
}

$params[] = $projectId;
$params[] = $userId;

$stmt = $pdo->prepare("
    UPDATE projects
    SET " . implode(", ", $updates) . "
    WHERE id = ? AND user_id = ?
");
$stmt->execute($params);

http_response_code(200);
echo "OK";
