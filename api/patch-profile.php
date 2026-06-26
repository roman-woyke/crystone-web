<?php
require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo "Unauthorized";
    exit;
}

$newUsername     = trim($_POST["username"]         ?? "");
$currentPassword = $_POST["current_password"]      ?? "";
$newPassword     = $_POST["new_password"]          ?? "";

if ($currentPassword === "") {
    http_response_code(400);
    echo "Current password is required.";
    exit;
}

$st = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
$st->execute([$_SESSION["user_id"]]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row || !password_verify($currentPassword, $row["password_hash"])) {
    http_response_code(403);
    echo "Current password is incorrect.";
    exit;
}

$changed = false;

if ($newUsername !== "" && $newUsername !== ($_SESSION["username"] ?? "")) {
    if (mb_strlen($newUsername) < 2 || mb_strlen($newUsername) > 50) {
        http_response_code(400);
        echo "Username must be 2-50 characters.";
        exit;
    }
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $newUsername)) {
        http_response_code(400);
        echo "Username may only contain letters, numbers, underscores and hyphens.";
        exit;
    }
    $st = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $st->execute([$newUsername, $_SESSION["user_id"]]);
    if ($st->fetch()) {
        http_response_code(409);
        echo "Username already taken.";
        exit;
    }
    $pdo->prepare("UPDATE users SET username = ? WHERE id = ?")->execute([$newUsername, $_SESSION["user_id"]]);
    $_SESSION["username"] = $newUsername;
    $changed = true;
}

if ($newPassword !== "") {
    if (mb_strlen($newPassword) < 8) {
        http_response_code(400);
        echo "New password must be at least 8 characters.";
        exit;
    }
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $_SESSION["user_id"]]);
    $changed = true;
}

if (!$changed) {
    http_response_code(400);
    echo "Nothing to update.";
    exit;
}

header("Content-Type: application/json");
echo json_encode(["ok" => true, "username" => $_SESSION["username"]]);
