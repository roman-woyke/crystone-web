<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../includes/boardle.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

$userId = (int) $_SESSION["user_id"];

// One row per day this user ever finished (solved or not) — backs the calendar
// popup's per-day markers. Small dataset for a small friend group; no range
// filtering needed.
$stmt = $pdo->prepare("SELECT game_date, finished, solved FROM boardle_results WHERE user_id = ?");
$stmt->execute([$userId]);
$results = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $results[$r["game_date"]] = [
        "finished" => (bool) $r["finished"],
        "solved"   => (bool) $r["solved"],
    ];
}

header("Content-Type: application/json");
echo json_encode([
    "today"         => date("Y-m-d"),
    "earliest_date" => boardleEarliestDate($pdo),
    "results"       => $results,
]);
