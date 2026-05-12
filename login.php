<?php

require_once __DIR__ . "/includes/start-session.php";

if (isset($_SESSION["user_id"])) {
    header("Location: /basti/dashboard.php");
    exit;
}

require_once __DIR__ . "/includes/header.php";
?>

<body>
    <h1>Login</h1>

    <form action="/basti/api/login.php" method="POST">
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
        <a href="/basti/register.php">Register</a>
    </p>
</body>
</html>