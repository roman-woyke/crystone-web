<?php

require_once __DIR__ . "/../includes/start-session.php";

require_once __DIR__ . "/../../config.php";

//die("Registration disabled.");

$username = trim($_POST["username"] ?? "");
$password = $_POST["password"] ?? "";
$inviteCode = trim($_POST["invite_code"] ?? "");

if ($username === "" || $password === "") {
    die("Username and password are required.");
}

$correctInviteCode = "internship2026";

if ($inviteCode !== $correctInviteCode) {
    die("Invalid invite code.");
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare("
        INSERT INTO users (username, password_hash)
        VALUES (?, ?)
    ");

    $stmt->execute([
        $username,
        $passwordHash
    ]);

    $_SESSION["user_id"] = $pdo->lastInsertId();
    $_SESSION["username"] = $username;

    header("Location: /dashboard.php");
    exit;

} catch (PDOException $e) {
    if ($e->getCode() === "23000") {
        die("Username already exists.");
    }

    die("Registration failed.");
}