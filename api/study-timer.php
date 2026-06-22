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

// ── Study segments: one row per contiguous study interval ────────────────────
// A "live" segment (session_id IS NULL) belongs to the in-progress session; the
// open one (ended_at IS NULL) is the interval currently running. Pausing closes
// it; resuming opens a new one. On log they're attached to the session so the
// day recap can draw the real intervals with breaks shown as gaps.
function segOpen(PDO $pdo, $userId): void
{
    $has = $pdo->prepare("SELECT 1 FROM study_segments WHERE user_id = ? AND session_id IS NULL AND ended_at IS NULL LIMIT 1");
    $has->execute([$userId]);
    if (!$has->fetchColumn()) {
        $pdo->prepare("INSERT INTO study_segments (user_id, started_at) VALUES (?, NOW())")->execute([$userId]);
    }
}
function segClose(PDO $pdo, $userId): void
{
    $pdo->prepare("UPDATE study_segments SET ended_at = NOW() WHERE user_id = ? AND session_id IS NULL AND ended_at IS NULL")
        ->execute([$userId]);
}
function segClearLive(PDO $pdo, $userId): void
{
    $pdo->prepare("DELETE FROM study_segments WHERE user_id = ? AND session_id IS NULL")->execute([$userId]);
}

// Current row (if any)
$stmt = $pdo->prepare("SELECT mode, module_name, started_at, accumulated, session_start, at_library FROM study_status WHERE user_id = ?");
$stmt->execute([$userId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$extra = [];

if ($action === "start") {
    if ($row && $row["mode"] === "timer") {
        // Resume a paused timer (no-op if already running).
        if ($row["started_at"] === null) {
            $pdo->prepare("UPDATE study_status SET started_at = NOW() WHERE user_id = ?")
                ->execute([$userId]);
            segOpen($pdo, $userId);
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

        // session_start = NOW() marks the real wall-clock start of the session;
        // it survives pause/resume so the day recap can show the true span.
        $pdo->prepare("
            INSERT INTO study_status (user_id, mode, module_name, started_at, accumulated, session_start)
            VALUES (?, 'timer', ?, NOW(), 0, NOW())
            ON DUPLICATE KEY UPDATE
                mode = 'timer', module_name = VALUES(module_name),
                started_at = NOW(), accumulated = 0, session_start = NOW()
        ")->execute([$userId, $resolved]);

        segClearLive($pdo, $userId);
        segOpen($pdo, $userId);

        $extra["custom_added"] = $customAdded;
    }
} elseif ($action === "presence") {
    // Quick "I'm studying" stopwatch with no module (not loggable).
    if (!$row) {
        $pdo->prepare("
            INSERT INTO study_status (user_id, mode, module_name, started_at, accumulated, session_start)
            VALUES (?, 'presence', NULL, NOW(), 0, NOW())
        ")->execute([$userId]);
        segClearLive($pdo, $userId);
        segOpen($pdo, $userId);
    } elseif ($row["started_at"] === null) {
        // Resume whatever was paused.
        $pdo->prepare("UPDATE study_status SET started_at = NOW() WHERE user_id = ?")
            ->execute([$userId]);
        segOpen($pdo, $userId);
    }
} elseif ($action === "resume") {
    // Come back from a break — works for both timer and presence rows.
    if ($row && $row["started_at"] === null) {
        $pdo->prepare("UPDATE study_status SET started_at = NOW() WHERE user_id = ?")
            ->execute([$userId]);
        segOpen($pdo, $userId);
    }
} elseif ($action === "pause") {
    // "I'm on break" — pauses whatever is running (timer or presence).
    if ($row && $row["started_at"] !== null) {
        $pdo->prepare("
            UPDATE study_status
            SET accumulated = accumulated + TIMESTAMPDIFF(SECOND, started_at, NOW()), started_at = NULL
            WHERE user_id = ?
        ")->execute([$userId]);
        segClose($pdo, $userId);
    }
} elseif ($action === "library") {
    // Toggle the "at the library" flag (BIB) on the current row. Independent
    // from running/break — you can be at the library while studying or on break.
    // We explicitly preserve `updated_at` so the break_elapsed timer doesn't
    // reset when you toggle BIB during a break (the table's ON UPDATE rule only
    // fires when columns change, but updated_at = updated_at is a no-op).
    if ($row) {
        $next = (int) !$row["at_library"];
        $pdo->prepare("UPDATE study_status SET at_library = ?, updated_at = updated_at WHERE user_id = ?")
            ->execute([$next, $userId]);
    }
} elseif ($action === "reset" || $action === "stop") {
    // Reset (timer) / Stop studying (presence) — discard the session entirely.
    segClearLive($pdo, $userId);
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

        // Close the currently-open interval so the elapsed time is captured.
        segClose($pdo, $userId);

        $e = $pdo->prepare("
            SELECT accumulated + IF(started_at IS NULL, 0, TIMESTAMPDIFF(SECOND, started_at, NOW()))
            FROM study_status WHERE user_id = ?
        ");
        $e->execute([$userId]);
        $elapsed = (int) $e->fetchColumn();

        if ($logModule !== null && $elapsed > 0 && $elapsed <= 86400) {
            // started_at = the real session start (with breaks); the recap uses it
            // to draw the true window. Falls back to NULL for pre-migration rows.
            // at_library is carried over so the "library" podium filter can replay
            // it later — the flag at log time is what gets stamped on the session.
            $pdo->prepare("
                INSERT INTO study_sessions (user_id, module_name, seconds, studied_on, started_at, at_library)
                VALUES (?, ?, ?, CURDATE(), ?, ?)
            ")->execute([$userId, $logModule, $elapsed, $row["session_start"] ?? null, (int) ($row["at_library"] ?? 0)]);

            // Attach this session's live segments so the recap can show its
            // exact study intervals (breaks render as the gaps between them).
            $sessionId = (int) $pdo->lastInsertId();
            $pdo->prepare("UPDATE study_segments SET session_id = ? WHERE user_id = ? AND session_id IS NULL")
                ->execute([$sessionId, $userId]);

            $extra["logged"]  = true;
            $extra["seconds"] = $elapsed;
            $extra["module"]  = $logModule;
        } else {
            // Nothing logged — drop the orphan segments.
            segClearLive($pdo, $userId);
        }

        $pdo->prepare("DELETE FROM study_status WHERE user_id = ?")->execute([$userId]);
    }
} else {
    http_response_code(400);
    exit("Invalid action.");
}

header("Content-Type: application/json");
echo json_encode(array_merge($extra, studyStatusPayload($pdo, $userId)));
