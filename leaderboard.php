<?php

require_once __DIR__ . "/includes/session.php";

$pageTitle = "Leaderboard";
require_once __DIR__ . "/includes/header.php";
?>

<style>
/* Give the leaderboard more room than the default 1200px container */
main.container {
    max-width: 1600px;
}
</style>

    <h1 class="page-heading">Internship Application <span class="gradient-text">Leaderboard</span></h1>

<?php
require_once __DIR__ . "/score-table.php";
require_once __DIR__ . "/score-chart.php";
?>

<?php require_once __DIR__ . "/includes/footer.php"; ?>
