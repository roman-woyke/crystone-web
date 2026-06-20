<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

$users = ["Roman", "Basti", "Ben", "Lorenz"];

function canonicalUser(?string $name, array $users): ?string {
    if ($name === null) return null;
    foreach ($users as $u) {
        if (strcasecmp($u, $name) === 0) return $u;
    }
    return null;
}

$targetUser = canonicalUser($_POST["username"] ?? "", $users);
$sessionUser = canonicalUser($_SESSION["username"] ?? "", $users);

if ($targetUser === null) {
    http_response_code(400);
    exit("Invalid user.");
}

if ($sessionUser === null || $sessionUser !== $targetUser) {
    http_response_code(403);
    exit("You can only modify your own exams.");
}

$examId  = (int) ($_POST["exam_id"] ?? 0);
$checked = ($_POST["checked"] ?? "") === "1";

if ($examId <= 0) {
    http_response_code(400);
    exit("Missing exam_id.");
}

if ($checked) {
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO user_exams (username, exam_id) VALUES (?, ?)
    ");
    $stmt->execute([$targetUser, $examId]);
} else {
    $stmt = $pdo->prepare("
        DELETE FROM user_exams WHERE username = ? AND exam_id = ?
    ");
    $stmt->execute([$targetUser, $examId]);
}

http_response_code(200);
echo "OK";
