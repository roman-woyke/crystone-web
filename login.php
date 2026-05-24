<?php

require_once __DIR__ . "/includes/start-session.php";
require_once __DIR__ . "/../../config.php";

if (isset($_SESSION["user_id"])) {
    header("Location: " . BASE_PATH . "/dashboard.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"]     ?? "";

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

    $_SESSION["user_id"]  = $user["id"];
    $_SESSION["username"] = $user["username"];

    header("Location: " . BASE_PATH . "/dashboard.php");
    exit;
}

require_once __DIR__ . "/includes/header.php";
?>

    <h1>Login</h1>

    <form action="<?= BASE_PATH ?>/login.php" method="POST">
        <div>
            <label>Username</label>
            <input type="text" name="username" required>
        </div>

        <div>
            <label>Password</label>
            <input type="password" name="password" required>
        </div>

        <button type="submit">Login</button>
    </form>

    <p>
        No account yet?
        <a href="<?= BASE_PATH ?>/register.php">Register</a>
    </p>

<?php require_once __DIR__ . "/includes/footer.php"; ?>
