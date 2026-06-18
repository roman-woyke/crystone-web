<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../../config.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

// ── Module list: exam-calendar titles (defaults) + custom study modules ──
$examTitles = $pdo->query("SELECT DISTINCT title FROM exams ORDER BY title")
    ->fetchAll(PDO::FETCH_COLUMN);
$customMods = $pdo->query("SELECT name FROM study_modules ORDER BY name")
    ->fetchAll(PDO::FETCH_COLUMN);

$modules = [];
$seen = [];
foreach ($examTitles as $t) {
    $key = mb_strtolower($t);
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $modules[] = ["name" => $t, "custom" => false];
}
foreach ($customMods as $c) {
    $key = mb_strtolower($c);
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    $modules[] = ["name" => $c, "custom" => true];
}

// ── Sessions aggregated per user + module + day ──────────────────────────
$stmt = $pdo->query("
    SELECT u.username, s.module_name, s.studied_on, SUM(s.seconds) AS seconds
    FROM study_sessions s
    JOIN users u ON u.id = s.user_id
    GROUP BY u.username, s.module_name, s.studied_on
    ORDER BY s.studied_on
");

$sessions = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $sessions[] = [
        "username" => $r["username"],
        "module"   => $r["module_name"],
        "date"     => $r["studied_on"],
        "seconds"  => (int) $r["seconds"],
    ];
}

header("Content-Type: application/json");
echo json_encode([
    "modules"  => $modules,
    "sessions" => $sessions,
]);
