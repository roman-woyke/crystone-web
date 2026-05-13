<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

// Base score points per status (PENDING/REJECTED/GHOSTED unaffected by tag)
$baseWeights = [
    "PENDING"  => 2,
    "REJECTED" => 1,
    "GHOSTED"  => 1,
];

// Tag-dependent weights for INTERVIEW and OFFER
$tagWeights = [
    "INTERVIEW" => [
        "MAYBE"           => 5,
        "PROBABLY"        => 7,
        "FOR SURE"        => 10,
        "ABSOLUTE CINEMA" => 15,
        ""                => 10,
    ],
    "OFFER" => [
        "MAYBE"           => 10,
        "PROBABLY"        => 14,
        "FOR SURE"        => 20,
        "ABSOLUTE CINEMA" => 30,
        ""                => 20,
    ],
];

function scorePoints(string $status, ?string $tag): int {
    global $baseWeights, $tagWeights;
    if (isset($baseWeights[$status])) return $baseWeights[$status];
    $tag = $tag ?? "";
    return $tagWeights[$status][$tag] ?? $tagWeights[$status][""];
}

// ── 1. Get all users ─────────────────────────────────────────────────
$usersStmt = $pdo->query("SELECT id, username FROM users ORDER BY id ASC");
$users = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

// ── 2. Get application tags lookup ───────────────────────────────────
$tagStmt = $pdo->query("SELECT id, tag FROM applications");
$appTags = [];
foreach ($tagStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $appTags[$row["id"]] = $row["tag"];
}

// ── 3. Get all status history events, ordered chronologically ────────
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

// ── 4. Replay history to build daily score deltas per user ───────────
//
// Track each application's last known status.
// When status changes, compute the score delta (new points - old points).
// Accumulate deltas per day.

// $trackedStatus[user_id][application_id] = last known status
$trackedStatus = [];

// $dailyDeltas[user_id][date] = net score change on that date
$dailyDeltas = [];

foreach ($historyRows as $row) {
    $uid    = $row["user_id"];
    $appId  = $row["application_id"];
    $status = $row["status"];
    $date   = $row["event_date"];
    $tag    = $appTags[$appId] ?? null;

    $oldStatus = $trackedStatus[$uid][$appId] ?? null;
    $oldPoints = $oldStatus !== null ? scorePoints($oldStatus, $tag) : 0;
    $newPoints = scorePoints($status, $tag);
    $delta     = $newPoints - $oldPoints;

    $dailyDeltas[$uid][$date] = ($dailyDeltas[$uid][$date] ?? 0) + $delta;

    $trackedStatus[$uid][$appId] = $status;
}

// ── 4. Build the response ─────────────────────────────────────────────
//
// For each user: a list of { date, score } where score is cumulative.
// Prepend a zero point one day before the first event.

$result = [];

foreach ($users as $user) {
    $uid      = $user["id"];
    $username = $user["username"];

    $deltas = $dailyDeltas[$uid] ?? [];
    ksort($deltas);

    $cumulative = 0;
    $points     = [];

    // Prepend a zero point one day before the first event
    // so every user's line visibly starts from 0
    if (count($deltas) > 0) {
        $firstDate = array_key_first($deltas);
        $dayBefore = date("Y-m-d", strtotime($firstDate . " -1 day"));
        $points[]  = ["date" => $dayBefore, "score" => 0];
    }

    foreach ($deltas as $date => $delta) {
        $cumulative += $delta;
        $points[] = [
            "date"  => $date,
            "score" => $cumulative,
        ];
    }

    if (count($points) > 0) {
        $result[] = [
            "username" => $username,
            "points"   => $points,
        ];
    }
}

header("Content-Type: application/json");
echo json_encode($result);