<?php

require_once __DIR__ . "/start-session.php";
require_once __DIR__ . "/../../config.php";

if (!isset($_SESSION["user_id"])) {
    $next = $_SERVER["REQUEST_URI"] ?? "";
    $loginUrl = BASE_PATH . "/login.php";
    if ($next !== "" && strpos($next, BASE_PATH . "/") === 0) {
        $loginUrl .= "?next=" . urlencode($next);
    }
    header("Location: " . $loginUrl);
    exit;
}
