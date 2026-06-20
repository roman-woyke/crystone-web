<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

// All runs ordered by WPM; the first row per user is their best run.
$stmt = $pdo->query("
    SELECT t.user_id, u.username, t.wpm, t.accuracy, t.created_at
    FROM typing_scores t
    JOIN users u ON u.id = t.user_id
    ORDER BY t.wpm DESC, t.created_at ASC
");

$best = [];
$runs = [];

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $uid = $row["user_id"];
    $runs[$uid] = ($runs[$uid] ?? 0) + 1;

    if (!isset($best[$uid])) {
        $best[$uid] = [
            "username"   => $row["username"],
            "wpm"        => (float) $row["wpm"],
            "accuracy"   => (float) $row["accuracy"],
            "created_at" => $row["created_at"],
        ];
    }
}

$result = [];
foreach ($best as $uid => $entry) {
    $entry["runs"] = $runs[$uid];
    $result[] = $entry;
}

header("Content-Type: application/json");
echo json_encode($result);
