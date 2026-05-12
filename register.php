<?php
session_start();

if (isset($_SESSION["user_id"])) {
    header("Location: /basti/dashboard.php");
    exit;
}

require_once __DIR__ . "/includes/header.php";
?>
    <h1>Create account</h1>

    <form action="/basti/api/register.php" method="POST">
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
        <a href="/basti/login.php">Login</a>
    </p>
</body>
</html>