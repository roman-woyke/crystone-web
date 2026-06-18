<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../../config.php";
require_once __DIR__ . "/../includes/study-status.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

$userId = $_SESSION["user_id"];

$stmt = $pdo->prepare("SELECT mode FROM study_status WHERE user_id = ?");
$stmt->execute([$userId]);
$mode = $stmt->fetchColumn();

if ($mode === "timer") {
    // A running/paused timer already marks the user as studying — don't let the
    // manual flag interfere. Stop the timer to stop studying.
} elseif ($mode === "presence") {
    $pdo->prepare("DELETE FROM study_status WHERE user_id = ?")->execute([$userId]);
} else {
    $pdo->prepare("
        INSERT INTO study_status (user_id, mode) VALUES (?, 'presence')
        ON DUPLICATE KEY UPDATE mode = 'presence'
    ")->execute([$userId]);
}

header("Content-Type: application/json");
echo json_encode(studyStatusPayload($pdo, $userId));
