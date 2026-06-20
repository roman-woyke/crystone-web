<?php

// Everyone's in Germany — make all PHP date/time work in local time, not the
// server's UTC. (The MySQL session timezone is set alongside in config.php so
// NOW()/CURDATE()/CURRENT_TIMESTAMP match.)
date_default_timezone_set("Europe/Berlin");

$lifetime = 60 * 60 * 24 * 30; // 30 days

session_set_cookie_params([
    "lifetime" => $lifetime,
    "path" => "/",
    "secure" => isset($_SERVER["HTTPS"]),
    "httponly" => true,
    "samesite" => "Lax"
]);

session_start();