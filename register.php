<?php

require_once __DIR__ . "/includes/start-session.php";
require_once __DIR__ . "/../../config.php";

if (isset($_SESSION["user_id"])) {
    header("Location: " . BASE_PATH . "/dashboard.php");
    exit;
}

$error = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    //$error = "Registration disabled.";

    $username   = trim($_POST["username"]    ?? "");
    $password   = $_POST["password"]         ?? "";
    $inviteCode = trim($_POST["invite_code"] ?? "");

    if ($username === "" || $password === "") {
        $error = "Username and password are required.";
    } elseif ($inviteCode !== INVITE_CODE) {
        $error = "Invalid invite code.";
    } else {
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
                $error = "Username already exists.";
            } else {
                $error = "Registration failed.";
            }
        }
    }
}

$pageTitle = "Register";
require_once __DIR__ . "/includes/header.php";
?>

    <div class="auth-card glass-card">
        <div class="auth-brand">
            <span class="brand-mark">IT</span>
            <span>InternTrack</span>
        </div>

        <h1>Create <span class="gradient-text">account</span></h1>
        <p class="auth-sub">Join the race. Every application scores points.</p>

        <?php if ($error !== null): ?>
            <div class="form-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

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

            <button type="submit" class="btn-primary">Register</button>
        </form>

        <p class="auth-alt">
            Already have an account?
            <a href="<?= BASE_PATH ?>/login.php">Login</a>
        </p>
    </div>

<?php require_once __DIR__ . "/includes/footer.php"; ?>
