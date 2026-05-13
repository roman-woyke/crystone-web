<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

// ── 1. Get all users ─────────────────────────────────────────────────
$usersStmt = $pdo->query("SELECT id, username FROM users ORDER BY id ASC");
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

// ── 2. Get full status history in strict chronological order ─────────
//    We need changed_at (not just the date) to preserve intra-day order
$historyStmt = $pdo->query("
    SELECT
        user_id,
        application_id,
        status,
        DATE(changed_at) AS event_date
    FROM application_status_history
    ORDER BY changed_at ASC
");
$historyRows = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

// Score weights — used to compute delta per status change
$scoreWeights = [
    "PENDING"   => 2,
    "REJECTED"  => 1,
    "GHOSTED"   => 1,
    "INTERVIEW" => 10,
    "OFFER"     => 20,
];

// ── 3. Build ordered runs per user per day ───────────────────────────
//
// We walk events in chronological order and group consecutive same-status
// events into runs. A run resets when the status changes.
//
// Each run includes a score_delta: the net score change for that run,
// computed as (newWeight - oldWeight) * count.
// e.g. PENDING→REJECTED = (1 - 2) * 1 = -1
//      new PENDING       = (2 - 0) * 1 = +2
//
// Output: $dailyRuns[user_id][date] = [
//     ["status" => "PENDING",  "count" => 2, "score_delta" => 4],
//     ["status" => "REJECTED", "count" => 1, "score_delta" => -1],
//     ...
// ]

// $trackedStatus[user_id][application_id] = last known status
$trackedStatus = [];

// $dailyRuns[user_id][date] = array of run objects
$dailyRuns = [];

foreach ($historyRows as $row) {
    $uid       = $row["user_id"];
    $appId     = $row["application_id"];
    $newStatus = $row["status"];
    $date      = $row["event_date"];

    $oldStatus = $trackedStatus[$uid][$appId] ?? null;

    // Skip if status didn't actually change (duplicate log entry)
    if ($oldStatus === $newStatus) {
        continue;
    }

    $oldWeight  = $oldStatus !== null ? $scoreWeights[$oldStatus] : 0;
    $newWeight  = $scoreWeights[$newStatus];
    $scoreDelta = $newWeight - $oldWeight;

    $trackedStatus[$uid][$appId] = $newStatus;

    // Initialise day array if needed
    if (!isset($dailyRuns[$uid][$date])) {
        $dailyRuns[$uid][$date] = [];
    }

    // Only bundle with the previous run if it's the same day AND same status AND same per-item delta
    $lastIdx = count($dailyRuns[$uid][$date]) - 1;
    if ($lastIdx >= 0
        && $dailyRuns[$uid][$date][$lastIdx]["status"] === $newStatus
        && ($dailyRuns[$uid][$date][$lastIdx]["score_delta"] / $dailyRuns[$uid][$date][$lastIdx]["count"]) === $scoreDelta
    ) {
        $dailyRuns[$uid][$date][$lastIdx]["count"]++;
        $dailyRuns[$uid][$date][$lastIdx]["score_delta"] += $scoreDelta;
    } else {
        $dailyRuns[$uid][$date][] = ["status" => $newStatus, "count" => 1, "score_delta" => $scoreDelta];
    }
}

// ── 4. Build response ────────────────────────────────────────────────

$result = [];

foreach ($users as $user) {
    $uid      = $user["id"];
    $username = $user["username"];

    if (!isset($dailyRuns[$uid])) continue;

    $days = [];
    foreach ($dailyRuns[$uid] as $date => $runs) {
        $days[$date] = $runs;
    }

    if (count($days) > 0) {
        $result[] = [
            "username" => $username,
            "days"     => $days,
        ];
    }
}

header("Content-Type: application/json");
echo json_encode($result);