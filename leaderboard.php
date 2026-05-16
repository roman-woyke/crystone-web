<?php

require_once __DIR__ . "/includes/session.php";
require_once __DIR__ . "/includes/header.php";
?>
<body>

    <h1>Internship Application Leaderboard</h1>

    <p>
        <a href="/ben/dashboard.php">Dashboard</a> |
        <a href="/ben/logout.php">Logout</a>
    </p>
    
<?php
require_once __DIR__ . "/score-table.php";
require_once __DIR__ . "/score-chart.php";
?>

<?php
require_once __DIR__ . "/includes/footer.php";
?>
