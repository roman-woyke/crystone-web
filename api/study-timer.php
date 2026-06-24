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
function segOpen(PDO $pdo, $userId, ?string $module = null): void
{
    $has = $pdo->prepare("SELECT 1 FROM study_segments WHERE user_id = ? AND session_id IS NULL AND ended_at IS NULL LIMIT 1");
    $has->execute([$userId]);
    if (!$has->fetchColumn()) {
        // Stamp the interval with the module that's active right now, so a
        // session split across modules keeps each interval's own module.
        $pdo->prepare("INSERT INTO study_segments (user_id, module_name, started_at) VALUES (?, ?, NOW())")
            ->execute([$userId, $module]);
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
            segOpen($pdo, $userId, $row["module_name"]);
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
        segOpen($pdo, $userId, $resolved);

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
        segOpen($pdo, $userId, null);
    } elseif ($row["started_at"] === null) {
        // Resume whatever was paused.
        $pdo->prepare("UPDATE study_status SET started_at = NOW() WHERE user_id = ?")
            ->execute([$userId]);
        segOpen($pdo, $userId, $row["module_name"]);
    }
} elseif ($action === "resume") {
    // Come back from a break — works for both timer and presence rows.
    if ($row && $row["started_at"] === null) {
        $pdo->prepare("UPDATE study_status SET started_at = NOW() WHERE user_id = ?")
            ->execute([$userId]);
        segOpen($pdo, $userId, $row["module_name"]);
    }
} elseif ($action === "set_module") {
    // Assign or switch the module of the current "I'm studying" session. The
    // first assignment stamps the running interval in place (so the pre-pick
    // time isn't orphaned); a real switch closes the interval under the old
    // module and opens a fresh one — both then show as separate sub-sessions in
    // the recap and the stop checklist.
    if (!$row) {
        http_response_code(400);
        exit("Not studying.");
    }
    $module    = trim($_POST["module"]     ?? "");
    $newModule = trim($_POST["new_module"] ?? "");
    $customAdded = false;
    $resolved = resolveStudyModule($pdo, $userId, $module, $newModule, $customAdded);
    if ($resolved === null) {
        http_response_code(400);
        exit("Pick a module first.");
    }

    // The currently-open (running) interval, if any.
    $openStmt = $pdo->prepare("SELECT id, module_name FROM study_segments WHERE user_id = ? AND session_id IS NULL AND ended_at IS NULL ORDER BY id DESC LIMIT 1");
    $openStmt->execute([$userId]);
    $open = $openStmt->fetch(PDO::FETCH_ASSOC);

    $pdo->prepare("UPDATE study_status SET module_name = ? WHERE user_id = ?")
        ->execute([$resolved, $userId]);

    if ($open) {
        if ($open["module_name"] === null) {
            // First assignment — stamp the running interval in place.
            $pdo->prepare("UPDATE study_segments SET module_name = ? WHERE id = ?")
                ->execute([$resolved, $open["id"]]);
        } elseif (strcasecmp($open["module_name"], $resolved) !== 0) {
            // Switching modules — close the current interval, open a fresh one.
            segClose($pdo, $userId);
            segOpen($pdo, $userId, $resolved);
        }
        // Same module re-selected → nothing to do.
    }
    // Paused (no open interval): the module is just updated; the next resume
    // opens a fresh interval under it.

    $extra["custom_added"] = $customAdded;
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
    // Stop & log: the session may span several modules (one sub-session per
    // module). The client sends `modules` — a JSON array of the module names to
    // keep — and we write one study_sessions row per kept module, summing that
    // module's live intervals server-side. Unchecked modules (and any un-mapped
    // time) are discarded.
    $extra["logged"]  = false;
    $extra["count"]   = 0;
    $extra["seconds"] = 0;
    $extra["modules"] = [];
    if ($row) {
        // Close the currently-open interval so its elapsed time is captured.
        segClose($pdo, $userId);

        $keep = json_decode($_POST["modules"] ?? "[]", true);
        if (!is_array($keep)) {
            $keep = [];
        }
        // Lower-cased set of module names to keep, for case-insensitive matching.
        $keepLc = [];
        foreach ($keep as $k) {
            $keepLc[mb_strtolower(trim((string) $k))] = true;
        }

        // Group this session's live intervals by module.
        $groups = $pdo->prepare("
            SELECT module_name,
                   SUM(TIMESTAMPDIFF(SECOND, started_at, ended_at)) AS seconds,
                   MIN(started_at) AS start_at
            FROM study_segments
            WHERE user_id = ? AND session_id IS NULL AND ended_at IS NOT NULL
            GROUP BY module_name
        ");
        $groups->execute([$userId]);

        $loggedSeconds = 0;
        $loggedCount   = 0;
        $loggedModules = [];
        foreach ($groups->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $mod  = $g["module_name"];
            $secs = (int) $g["seconds"];
            if ($mod === null) continue;                                  // un-mapped time isn't loggable
            if (!isset($keepLc[mb_strtolower($mod)])) continue;            // module not checked
            if ($secs <= 0 || $secs > 86400) continue;

            // started_at = the real sub-session start (with breaks); at_library is
            // carried over so the "library" podium filter can replay it later.
            $pdo->prepare("
                INSERT INTO study_sessions (user_id, module_name, seconds, studied_on, started_at, at_library)
                VALUES (?, ?, ?, CURDATE(), ?, ?)
            ")->execute([$userId, $mod, $secs, $g["start_at"], (int) ($row["at_library"] ?? 0)]);

            // Attach this module's intervals so the recap can draw their exact
            // spans (breaks render as the gaps between them).
            $sessionId = (int) $pdo->lastInsertId();
            $pdo->prepare("UPDATE study_segments SET session_id = ? WHERE user_id = ? AND session_id IS NULL AND module_name = ?")
                ->execute([$sessionId, $userId, $mod]);

            $loggedSeconds += $secs;
            $loggedCount++;
            $loggedModules[] = $mod;
        }

        // Drop any remaining unlogged intervals (un-mapped or unchecked modules).
        segClearLive($pdo, $userId);
        $pdo->prepare("DELETE FROM study_status WHERE user_id = ?")->execute([$userId]);

        $extra["logged"]  = $loggedCount > 0;
        $extra["count"]   = $loggedCount;
        $extra["seconds"] = $loggedSeconds;
        $extra["modules"] = $loggedModules;
    }
} else {
    http_response_code(400);
    exit("Invalid action.");
}

header("Content-Type: application/json");
echo json_encode(array_merge($extra, studyStatusPayload($pdo, $userId)));
