<?php

require_once __DIR__ . "/start-session.php";
require_once __DIR__ . "/../../config.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: " . BASE_PATH . "/login.php");
    exit;
}
