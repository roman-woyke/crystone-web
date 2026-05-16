<?php

require_once __DIR__ . "/start-session.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: /ben/login.php");
    exit;
}