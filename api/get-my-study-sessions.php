<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../includes/study-sessions.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

$userId = $_SESSION["user_id"];

// My own logged sessions (with ids), newest first, for the "manage previous
// sessions" window. `date` is the study-day the session is charted on (same
// 04:00-boundary attribution as the chart/recap) so the client can group by
// week exactly like the chart does.
$stmt = $pdo->prepare("
    SELECT id, module_name, seconds, studied_on, started_at, at_library, created_at
    FROM study_sessions
    WHERE user_id = ?
    ORDER BY COALESCE(started_at, created_at) DESC
");
$stmt->execute([$userId]);

$sessions = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $sessions[] = [
        "id"         => (int) $r["id"],
        "module"     => $r["module_name"],
        "seconds"    => (int) $r["seconds"],
        "date"       => studySessionDate($r["started_at"], $r["studied_on"]),
        "started_at" => $r["started_at"],
        "created_at" => $r["created_at"],
        "at_library" => (bool) $r["at_library"],
    ];
}

header("Content-Type: application/json");
echo json_encode(["sessions" => $sessions]);
