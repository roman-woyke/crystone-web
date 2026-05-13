<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

// Get every status event ordered chronologically
$stmt = $pdo->query("
    SELECT
        application_id,
        status
    FROM application_status_history
    ORDER BY changed_at ASC
");

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build trail per application, skipping consecutive duplicates
// e.g. PENDING, PENDING, INTERVIEW, REJECTED → PENDING, INTERVIEW, REJECTED
$trails = [];

foreach ($rows as $row) {
    $appId  = $row["application_id"];
    $status = $row["status"];

    if (!isset($trails[$appId])) {
        $trails[$appId] = [];
    }

    // Only append if different from the last recorded status
    $last = end($trails[$appId]);
    if ($last !== $status) {
        $trails[$appId][] = $status;
    }
}

// Only return trails that have more than one step — single-step trails
// (just PENDING, or just current status) add no extra information
// and would clutter every row in the table.
$result = [];

foreach ($trails as $appId => $trail) {
    if (count($trail) > 1) {
        $result[$appId] = $trail;
    }
}

header("Content-Type: application/json");
echo json_encode($result);