<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../includes/study-status.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

$userId = $_SESSION["user_id"];
$action = $_POST["action"] ?? "";

// Resolve a module against the allowed set (exam titles + custom modules),
// creating a new custom module when the name is genuinely new.
function resolveStudyModule(PDO $pdo, $userId, string $module, string $newModule, bool &$customAdded): ?string
{
    $customAdded = false;

    $examTitles = $pdo->query("SELECT DISTINCT title FROM exams")->fetchAll(PDO::FETCH_COLUMN);
    $customMods = $pdo->query("SELECT name FROM study_modules")->fetchAll(PDO::FETCH_COLUMN);
    $allowed = array_merge($examTitles, $customMods);

    if ($newModule !== "") {
        if (mb_strlen($newModule) > 255) return null;
        foreach ($allowed as $m) {
            if (strcasecmp($m, $newModule) === 0) return $m;
        }
        $pdo->prepare("INSERT INTO study_modules (name, created_by) VALUES (?, ?)")
            ->execute([$newModule, $userId]);
        $customAdded = true;
        return $newModule;
    }

    foreach ($allowed as $m) {
        if (strcasecmp($m, $module) === 0) return $m;
    }
    return null;
}

// Current row (if any)
$stmt = $pdo->prepare("SELECT mode, module_name, started_at, accumulated FROM study_status WHERE user_id = ?");
$stmt->execute([$userId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$extra = [];

if ($action === "start") {
    if ($row && $row["mode"] === "timer") {
        // Resume a paused timer (no-op if already running).
        if ($row["started_at"] === null) {
            $pdo->prepare("UPDATE study_status SET started_at = NOW() WHERE user_id = ?")
                ->execute([$userId]);
        }
    } else {
        // Fresh start (replaces any manual presence) — a module is required.
        $module    = trim($_POST["module"]     ?? "");
        $newModule = trim($_POST["new_module"] ?? "");
        $customAdded = false;
        $resolved = resolveStudyModule($pdo, $userId, $module, $newModule, $customAdded);

        if ($resolved === null) {
            http_response_code(400);
            exit("Pick a module first.");
        }

        $pdo->prepare("
            INSERT INTO study_status (user_id, mode, module_name, started_at, accumulated)
            VALUES (?, 'timer', ?, NOW(), 0)
            ON DUPLICATE KEY UPDATE
                mode = 'timer', module_name = VALUES(module_name), started_at = NOW(), accumulated = 0
        ")->execute([$userId, $resolved]);

        $extra["custom_added"] = $customAdded;
    }
} elseif ($action === "presence") {
    // Quick "I'm studying" stopwatch with no module (not loggable).
    if (!$row) {
        $pdo->prepare("
            INSERT INTO study_status (user_id, mode, module_name, started_at, accumulated)
            VALUES (?, 'presence', NULL, NOW(), 0)
        ")->execute([$userId]);
    } elseif ($row["started_at"] === null) {
        // Resume whatever was paused.
        $pdo->prepare("UPDATE study_status SET started_at = NOW() WHERE user_id = ?")
            ->execute([$userId]);
    }
} elseif ($action === "resume") {
    // Come back from a break — works for both timer and presence rows.
    if ($row && $row["started_at"] === null) {
        $pdo->prepare("UPDATE study_status SET started_at = NOW() WHERE user_id = ?")
            ->execute([$userId]);
    }
} elseif ($action === "pause") {
    // "I'm on break" — pauses whatever is running (timer or presence).
    if ($row && $row["started_at"] !== null) {
        $pdo->prepare("
            UPDATE study_status
            SET accumulated = accumulated + TIMESTAMPDIFF(SECOND, started_at, NOW()), started_at = NULL
            WHERE user_id = ?
        ")->execute([$userId]);
    }
} elseif ($action === "reset" || $action === "stop") {
    // Reset (timer) / Stop studying (presence) — clears the row either way.
    $pdo->prepare("DELETE FROM study_status WHERE user_id = ?")->execute([$userId]);
} elseif ($action === "log") {
    $extra["logged"] = false;
    if ($row) {
        // Module: an explicit override (stopping a module-less presence session
        // and choosing what to log it as) takes precedence over the row's module.
        $module    = trim($_POST["module"]     ?? "");
        $newModule = trim($_POST["new_module"] ?? "");
        if ($module !== "" || $newModule !== "") {
            $customAdded = false;
            $logModule = resolveStudyModule($pdo, $userId, $module, $newModule, $customAdded);
            $extra["custom_added"] = $customAdded;
        } else {
            $logModule = $row["module_name"]; // null for an un-mapped presence row
        }

        $e = $pdo->prepare("
            SELECT accumulated + IF(started_at IS NULL, 0, TIMESTAMPDIFF(SECOND, started_at, NOW()))
            FROM study_status WHERE user_id = ?
        ");
        $e->execute([$userId]);
        $elapsed = (int) $e->fetchColumn();

        if ($logModule !== null && $elapsed > 0 && $elapsed <= 86400) {
            $pdo->prepare("
                INSERT INTO study_sessions (user_id, module_name, seconds, studied_on)
                VALUES (?, ?, ?, CURDATE())
            ")->execute([$userId, $logModule, $elapsed]);

            $extra["logged"]  = true;
            $extra["seconds"] = $elapsed;
            $extra["module"]  = $logModule;
        }

        $pdo->prepare("DELETE FROM study_status WHERE user_id = ?")->execute([$userId]);
    }
} else {
    http_response_code(400);
    exit("Invalid action.");
}

header("Content-Type: application/json");
echo json_encode(array_merge($extra, studyStatusPayload($pdo, $userId)));
