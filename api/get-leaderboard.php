<?php

require_once __DIR__ . "/../includes/start-session.php";

require_once __DIR__ . "/../../config.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

// 1. Get leaderboard stats
$stmt = $pdo->query("
    SELECT
        users.id AS user_id,
        users.username,

        COUNT(applications.id) AS total_applications,

        COALESCE(SUM(applications.status = 'PENDING'), 0) AS pending,
        COALESCE(SUM(applications.status = 'REJECTED'), 0) AS rejected,
        COALESCE(SUM(applications.status = 'GHOSTED'), 0) AS ghosted,
        COALESCE(SUM(applications.status = 'INTERVIEW'), 0) AS interviews,
        COALESCE(SUM(applications.status = 'OFFER'), 0) AS offers,

        COALESCE(SUM(
            CASE applications.status
                WHEN 'OFFER' THEN
                    CASE applications.tag
                        WHEN 'MAYBE'           THEN 10
                        WHEN 'PROBABLY'        THEN 14
                        WHEN 'FOR SURE'        THEN 20
                        WHEN 'ABSOLUTE CINEMA' THEN 30
                        ELSE 20
                    END
                WHEN 'INTERVIEW' THEN
                    CASE applications.tag
                        WHEN 'MAYBE'           THEN 5
                        WHEN 'PROBABLY'        THEN 7
                        WHEN 'FOR SURE'        THEN 10
                        WHEN 'ABSOLUTE CINEMA' THEN 15
                        ELSE 10
                    END
                WHEN 'PENDING'  THEN 2
                WHEN 'GHOSTED'  THEN 1
                WHEN 'REJECTED' THEN 1
                ELSE 0
            END
        ), 0) AS score

    FROM users

    LEFT JOIN applications
        ON users.id = applications.user_id

    GROUP BY users.id, users.username

    ORDER BY score DESC,
             offers DESC,
             interviews DESC,
             total_applications DESC
");

$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Get all applications with their peak status from history
//
// Peak status is the highest-ranked status ever reached by an application,
// regardless of what it currently is.
// Priority: OFFER > INTERVIEW > PENDING > GHOSTED > REJECTED
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

        -- Peak status: highest priority status ever logged in history
        CASE MAX(
            CASE h.status
                WHEN 'OFFER'     THEN 5
                WHEN 'INTERVIEW' THEN 4
                WHEN 'PENDING'   THEN 3
                WHEN 'GHOSTED'   THEN 2
                WHEN 'REJECTED'  THEN 1
                ELSE 0
            END
        )
            WHEN 5 THEN 'OFFER'
            WHEN 4 THEN 'INTERVIEW'
            WHEN 3 THEN 'PENDING'
            WHEN 2 THEN 'GHOSTED'
            WHEN 1 THEN 'REJECTED'
            ELSE NULL
        END AS peak_status

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

// 3. Group applications by user_id
$applicationsByUser = [];

foreach ($allApplications as $application) {
    $userId = $application["user_id"];

    unset($application["user_id"]);

    if (!isset($applicationsByUser[$userId])) {
        $applicationsByUser[$userId] = [];
    }

    $applicationsByUser[$userId][] = $application;
}

// 4. Attach applications to each user
foreach ($users as &$user) {
    $userId = $user["user_id"];

    $user["applications"] = $applicationsByUser[$userId] ?? [];

    unset($user["user_id"]);
}

header("Content-Type: application/json");
echo json_encode($users);