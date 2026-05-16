<?php

require_once __DIR__ . "/includes/start-session.php";
require_once __DIR__ . "/../config.php";

if (isset($_SESSION["user_id"])) {
    header("Location: " . BASE_PATH . "/dashboard.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    //die("Registration disabled.");

    $username   = trim($_POST["username"]    ?? "");
    $password   = $_POST["password"]         ?? "";
    $inviteCode = trim($_POST["invite_code"] ?? "");

    if ($username === "" || $password === "") {
        die("Username and password are required.");
    }

    if ($inviteCode !== "internship2026") {
        die("Invalid invite code.");
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    try {
        $stmt = $pdo->prepare("
            INSERT INTO users (username, password_hash)
            VALUES (?, ?)
        ");

        $stmt->execute([$username, $passwordHash]);

        $_SESSION["user_id"]  = $pdo->lastInsertId();
        $_SESSION["username"] = $username;

        header("Location: " . BASE_PATH . "/dashboard.php");
        exit;

    } catch (PDOException $e) {
        if ($e->getCode() === "23000") {
            die("Username already exists.");
        }

        die("Registration failed.");
    }
}

require_once __DIR__ . "/includes/header.php";
?>

    <h1>Create account</h1>

    <form action="<?= BASE_PATH ?>/register.php" method="POST">
        <div>
            <label>Username</label>
            <input type="text" name="username" required>
        </div>

        <div>
            <label>Password</label>
            <input type="password" name="password" required>
        </div>

        <div>
            <label>Invite code</label>
            <input type="text" name="invite_code" required>
        </div>

        <button type="submit">Register</button>
    </form>

    <p>
        Already have an account?
        <a href="<?= BASE_PATH ?>/login.php">Login</a>
    </p>

<?php require_once __DIR__ . "/includes/footer.php"; ?>
