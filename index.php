<?php

require_once __DIR__ . "/basti/includes/start-session.php";

if (isset($_SESSION["user_id"])) {
    header("Location: /basti/study-counter.php");
} else {
    header("Location: /basti/login.php");
}

exit;