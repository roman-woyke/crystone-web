<?php
// LOCAL DOCKER CONFIG — this file IS committed to git on purpose.
// It only ever runs inside the local Docker container, talking to the
// local throwaway MySQL container. It is never used in production,
// because production's config.php lives one directory above the real
// webroot on Hostinger — a location this repo never touches.
//
// Structure mirrors the real config.php so all code paths behave the same.

define("INVITE_CODE", "localdev-invite");
// Served from the web root — no subfolder prefix.
define("BASE_PATH", "");

$pdo = new PDO(
    "mysql:host=db;dbname=local_site_db;charset=utf8mb4",
    "root",
    "rootpass"
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Same Berlin timezone handling as production, kept consistent on purpose.
$berlin = new DateTimeZone("Europe/Berlin");
$offset = $berlin->getOffset(new DateTime("now", $berlin));
$tzOff  = sprintf("%+03d:%02d", intdiv($offset, 3600), abs($offset % 3600) / 60);
$pdo->exec("SET time_zone = '$tzOff'");
