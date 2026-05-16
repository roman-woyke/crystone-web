<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../includes/scoring.php";

require_once __DIR__ . "/../../config.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

// 1. Get leaderboard stats (counts only — score is computed in PHP via scoring.php)
$stmt = $pdo->query("
    SELECT
        users.id AS user_id,
        users.username,

        COUNT(applications.id) AS total_applications,

        COALESCE(SUM(applications.status = 'PENDING'), 0) AS pending,
        COALESCE(SUM(applications.status = 'REJECTED'), 0) AS rejected,
        COALESCE(SUM(applications.status = 'GHOSTED'), 0) AS ghosted,
        COALESCE(SUM(applications.status = 'INTERVIEW'), 0) AS interviews,
        COALESCE(SUM(applications.status = 'OFFER'), 0) AS offers

    FROM users

    LEFT JOIN applications
        ON users.id = applications.user_id

    GROUP BY users.id, users.username
");

$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Compute scores in PHP using the shared scorePoints() function
$historyStmt = $pdo->query("
    SELECT h.status, a.tag, a.user_id
    FROM application_status_history h
    JOIN applications a ON a.id = h.application_id
");

$scoreByUser = [];
foreach ($historyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $uid = $row["user_id"];
    $scoreByUser[$uid] = ($scoreByUser[$uid] ?? 0) + scorePoints($row["status"], $row["tag"]);
}

foreach ($users as &$user) {
    $user["score"] = $scoreByUser[$user["user_id"]] ?? 0;
}
unset($user);

usort($users, fn($a, $b) =>
    $b["score"]               <=> $a["score"] ?:
    $b["offers"]              <=> $a["offers"] ?:
    $b["interviews"]          <=> $a["interviews"] ?:
    $b["total_applications"]  <=> $a["total_applications"]
);

// 3. Get all applications with their peak status from history
$appStmt = $pdo->query("
    SELECT
        a.user_id,
        a.id AS application_id,
        a.company_name,
        a.job_title,
        a.job_link,
        a.location,
        a.status,
        a.notes,
        a.tag,
        a.created_at,
        a.updated_at,

        " . peakStatusSql() . "

    FROM applications a
    LEFT JOIN application_status_history h
        ON h.application_id = a.id

    GROUP BY
        a.id,
        a.user_id,
        a.company_name,
        a.job_title,
        a.job_link,
        a.location,
        a.status,
        a.notes,
        a.tag,
        a.created_at,
        a.updated_at

    ORDER BY a.created_at DESC
");

$allApplications = $appStmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Group applications by user_id
$applicationsByUser = [];

foreach ($allApplications as $application) {
    $userId = $application["user_id"];

    unset($application["user_id"]);

    if (!isset($applicationsByUser[$userId])) {
        $applicationsByUser[$userId] = [];
    }

    $applicationsByUser[$userId][] = $application;
}

// 5. Attach applications to each user
foreach ($users as &$user) {
    $userId = $user["user_id"];

    $user["applications"] = $applicationsByUser[$userId] ?? [];

    unset($user["user_id"]);
}

header("Content-Type: application/json");
echo json_encode($users);
