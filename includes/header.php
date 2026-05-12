<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Internship Tracker</title>

    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>

<nav class="navbar">
    <div class="nav-left">
        <a href="/ben/dashboard.php">Dashboard</a>
        <a href="/ben/leaderboard.php">Leaderboard</a>
    </div>

    <div class="nav-right">
        <?php if (isset($_SESSION["username"])): ?>
            <span>
                <?= htmlspecialchars($_SESSION["username"]) ?>
            </span>
        <?php endif; ?>

        <a href="/ben/logout.php">Logout</a>
    </div>
</nav>

<main class="container">