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

// ── 2. Get raw events in strict chronological order ──────────────────
//
// We only want status transitions, not the initial PENDING creation,
// because those are already captured as the day-anchor points via
// get-score-history. We include all events here and let the JS
// handle the display logic.
$historyStmt = $pdo->query("
    SELECT
        user_id,
        application_id,
        status,
        DATE(changed_at)     AS event_date,
        changed_at           AS event_time
    FROM application_status_history
    ORDER BY changed_at ASC
");
$historyRows = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

// ── 3. Get application tags lookup ───────────────────────────────────
$tagStmt = $pdo->query("SELECT id, tag FROM applications");
$appTags = [];
foreach ($tagStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $appTags[$row["id"]] = $row["tag"];
}

// ── 4. Group raw events per user, preserving chronological order ─────
//
// We also track the previous status of each application so we can
// compute the score delta for each individual event.

require_once __DIR__ . "/../includes/scoring.php";

// $userEvents[user_id] = [ { date, status, score_delta }, ... ]
$userEvents = [];

foreach ($historyRows as $row) {
    $uid       = $row["user_id"];
    $appId     = $row["application_id"];
    $newStatus = $row["status"];
    $date      = $row["event_date"];
    $tag       = $appTags[$appId] ?? null;

    // Additive model: each event independently contributes its own points
    $delta = scorePoints($newStatus, $tag);

    $userEvents[$uid][] = [
        "date"        => $date,
        "status"      => $newStatus,
        "score_delta" => $delta,
    ];
}

// ── 4. Build response ────────────────────────────────────────────────
$result = [];

foreach ($users as $user) {
    $uid      = $user["id"];
    $username = $user["username"];

    $events = $userEvents[$uid] ?? [];

    if (count($events) === 0) continue;

    $result[] = [
        "username" => $username,
        "events"   => $events,
    ];
}

header("Content-Type: application/json");
echo json_encode($result);