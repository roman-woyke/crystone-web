<?php

$lifetime = 60 * 60 * 24 * 30; // 30 days

session_set_cookie_params([
    "lifetime" => $lifetime,
    "path" => "/",
    "secure" => isset($_SERVER["HTTPS"]),
    "httponly" => true,
    "samesite" => "Lax"
]);

session_start();