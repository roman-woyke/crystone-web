<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title><?= isset($pageTitle) ? htmlspecialchars($pageTitle) . " · InternTrack" : "InternTrack" ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap">

    <?php $cssPath = __DIR__ . "/../assets/css/style.css"; ?>
    <link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css?v=<?= file_exists($cssPath) ? filemtime($cssPath) : time() ?>">

    <?php $jsPath = __DIR__ . "/../assets/js/app.js"; ?>
    <script src="<?= BASE_PATH ?>/assets/js/app.js?v=<?= file_exists($jsPath) ? filemtime($jsPath) : time() ?>" defer></script>
</head>
<body>

<div class="bg-orbs" aria-hidden="true">
    <div class="orb orb-violet"></div>
    <div class="orb orb-blue"></div>
    <div class="orb orb-teal"></div>
</div>

<?php
    $loggedIn = isset($_SESSION["user_id"]);
    $currentPage = basename($_SERVER["SCRIPT_NAME"]);

    $navLinks = [
        "dashboard.php"   => "Dashboard",
        "leaderboard.php" => "Leaderboard",
        "calendar.php"    => "Calendar",
        "typing-game.php" => "Typing Battle",
        "study-counter.php" => "Study Counter",
        "projects.php"    => "Projects",
    ];
?>

<nav class="navbar">
    <div class="nav-left">
        <a class="nav-brand" href="<?= BASE_PATH ?><?= $loggedIn ? "/dashboard.php" : "/" ?>">
            <span class="brand-mark">IT</span>
            <span class="gradient-text">InternTrack</span>
        </a>

        <?php if ($loggedIn): ?>
            <div class="nav-links" id="nav-links">
                <?php foreach ($navLinks as $file => $label): ?>
                    <a
                        href="<?= BASE_PATH ?>/<?= $file ?>"
                        <?= $currentPage === $file ? 'class="active"' : "" ?>
                    ><?= $label ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="nav-right">
        <?php if ($loggedIn): ?>
            <?php if (isset($_SESSION["username"])): ?>
                <span class="nav-user-chip">
                    <?= htmlspecialchars($_SESSION["username"]) ?>
                </span>
            <?php endif; ?>

            <a class="btn btn-ghost" href="<?= BASE_PATH ?>/logout.php">Logout</a>

            <button class="nav-burger" id="nav-burger" type="button" aria-label="Toggle navigation" aria-expanded="false" aria-controls="nav-links">
                <span></span>
                <span></span>
                <span></span>
            </button>
        <?php else: ?>
            <a class="btn btn-ghost" href="<?= BASE_PATH ?>/login.php">Login</a>
            <a class="btn btn-primary" href="<?= BASE_PATH ?>/register.php">Register</a>
        <?php endif; ?>
    </div>
</nav>

<main class="container">
