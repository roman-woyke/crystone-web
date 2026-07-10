<?php

// Everyone's in Germany — make all PHP date/time work in local time, not the
// server's UTC. (The MySQL session timezone is set alongside in config.php so
// NOW()/CURDATE()/CURRENT_TIMESTAMP match.)
date_default_timezone_set("Europe/Berlin");

$lifetime = 60 * 60 * 24 * 30; // 30 days

// session_set_cookie_params only controls the cookie's expiry in the
// browser. Without also raising gc_maxlifetime, PHP's default (often 1440s
// = 24 minutes on shared hosts) garbage-collects the session data server
// side long before the cookie expires, so a valid cookie ends up pointing
// at nothing and the user gets bounced to login. Keep both in sync.
ini_set("session.gc_maxlifetime", (string) $lifetime);
ini_set("session.gc_probability", "1");
ini_set("session.gc_divisor", "1000");

session_set_cookie_params([
    "lifetime" => $lifetime,
    "path" => "/",
    "secure" => isset($_SERVER["HTTPS"]),
    "httponly" => true,
    "samesite" => "Lax"
]);

session_start();