<?php

require_once __DIR__ . "/includes/start-session.php";
require_once __DIR__ . "/../../config.php";

// Only accept `next` if it's a same-app relative path (prevents open redirect).
function safeNext(?string $next): string {
    if ($next === null || $next === "") return BASE_PATH . "/dashboard.php";
    if (strpos($next, BASE_PATH . "/") !== 0) return BASE_PATH . "/dashboard.php";
    return $next;
}

$next = safeNext($_GET["next"] ?? $_POST["next"] ?? "");

if (isset($_SESSION["user_id"])) {
    header("Location: " . $next);
    exit;
}

$error = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"]     ?? "";

    if ($username === "" || $password === "") {
        $error = "Username and password are required.";
    } else {
        $stmt = $pdo->prepare("
            SELECT id, username, password_hash
            FROM users
            WHERE username = ?
            LIMIT 1
        ");

        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user["password_hash"])) {
            $error = "Invalid username or password.";
        } else {
            $_SESSION["user_id"]  = $user["id"];
            $_SESSION["username"] = $user["username"];

            header("Location: " . $next);
            exit;
        }
    }
}

$pageTitle = "Login";
require_once __DIR__ . "/includes/header.php";
?>

    <div class="auth-card glass-card">
        <div class="auth-brand">
            <span class="brand-mark">IT</span>
            <span>InternTrack</span>
        </div>

        <h1>Welcome <span class="gradient-text">back</span></h1>
        <p class="auth-sub">Log in to keep climbing the leaderboard.</p>

        <?php if ($error !== null): ?>
            <div class="form-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form action="<?= BASE_PATH ?>/login.php" method="POST">
            <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
            <div>
                <label>Username</label>
                <input type="text" name="username" required>
            </div>

            <div>
                <label>Password</label>
                <input type="password" name="password" required>
            </div>

            <button type="submit" class="btn-primary">Login</button>
        </form>

        <p class="auth-alt">
            No account yet?
            <a href="<?= BASE_PATH ?>/register.php">Register</a>
        </p>
    </div>

<?php require_once __DIR__ . "/includes/footer.php"; ?>
