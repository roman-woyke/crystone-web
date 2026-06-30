<?php
require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo "Unauthorized";
    exit;
}

$name = trim($_POST["name"] ?? "");
if ($name === "") {
    http_response_code(400);
    echo "Module name is required.";
    exit;
}

// Only custom modules (in study_modules) can be deleted.
// Default modules come from exams and are not stored here.
$pdo->prepare("DELETE FROM study_modules WHERE name = ?")->execute([$name]);

echo "OK";
