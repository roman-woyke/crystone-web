<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Internship Tracker</title>

    <?php $cssPath = __DIR__ . "/../assets/css/style.css"; ?>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css?v=<?= file_exists($cssPath) ? filemtime($cssPath) : time() ?>">
</head>
<body>

<nav class="navbar">
    <div class="nav-left">
        <a href="<?= BASE_PATH ?>/dashboard.php">Dashboard</a>
        <a href="<?= BASE_PATH ?>/leaderboard.php">Leaderboard</a>
        <a href="<?= BASE_PATH ?>/calendar.php">Calendar</a>
    </div>

    <div class="nav-right">
        <?php if (isset($_SESSION["username"])): ?>
            <span>
                <?= htmlspecialchars($_SESSION["username"]) ?>
            </span>
        <?php endif; ?>

        <a href="<?= BASE_PATH ?>/logout.php">Logout</a>
    </div>
</nav>

<main class="container">
