<?php

require_once __DIR__ . "/../includes/start-session.php";

require_once __DIR__ . "/../../config.php";

$username = trim($_POST["username"] ?? "");
$password = $_POST["password"] ?? "";

if ($username === "" || $password === "") {
    die("Username and password are required.");
}

$stmt = $pdo->prepare("
    SELECT id, username, password_hash
    FROM users
    WHERE username = ?
    LIMIT 1
");

$stmt->execute([$username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user["password_hash"])) {
    die("Invalid username or password.");
}

$_SESSION["user_id"] = $user["id"];
$_SESSION["username"] = $user["username"];

header("Location: /roman/dashboard.php");
exit;