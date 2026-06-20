<?php

require_once __DIR__ . "/includes/start-session.php";

if (isset($_SESSION["user_id"])) {
    header("Location: /study-counter.php");
} else {
    header("Location: /login.php");
}

exit;