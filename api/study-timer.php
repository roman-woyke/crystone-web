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
    // "I'm studying" — start a stopwatch. A module is normally picked up front
    // (the UI prompts for it), but we tolerate a module-less start too.
    if (!$row) {
        $module    = trim($_POST["module"]     ?? "");
        $newModule = trim($_POST["new_module"] ?? "");
        $resolved    = null;
        $customAdded = false;
        if ($module !== "" || $newModule !== "") {
            $resolved = resolveStudyModule($pdo, $userId, $module, $newModule, $customAdded);
        }
        $pdo->prepare("
            INSERT INTO study_status (user_id, mode, module_name, started_at, accumulated, session_start)
            VALUES (?, 'presence', ?, NOW(), 0, NOW())
        ")->execute([$userId, $resolved]);
        segClearLive($pdo, $userId);
        segOpen($pdo, $userId, $resolved);
        $extra["custom_added"] = $customAdded;
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
            // First assignment — stamp every still-unstamped interval of this
            // session (the open one plus any pre-pick intervals split off by
            // breaks), so none of that time shows up as an orphaned grey block.
            $pdo->prepare("UPDATE study_segments SET module_name = ? WHERE user_id = ? AND session_id IS NULL AND module_name IS NULL")
                ->execute([$resolved, $userId]);
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
    // module). The client sends `sessions` — a JSON array of `{module, seconds}`
    // — listing which modules to keep and the (possibly trimmed-down) duration
    // for each. We write one study_sessions row per kept module and trim its
    // intervals from the END to match the requested seconds (so "I forgot to
    // stop the timer" can be corrected). Modules not in the list are discarded.
    $extra["logged"]  = false;
    $extra["count"]   = 0;
    $extra["seconds"] = 0;
    $extra["modules"] = [];
    if ($row) {
        // Close the currently-open interval so its elapsed time is captured.
        segClose($pdo, $userId);

        $reqList = json_decode($_POST["sessions"] ?? "[]", true);
        if (!is_array($reqList)) {
            $reqList = [];
        }
        // module (lower-cased) → requested seconds (0/absent = keep full).
        $reqByMod = [];
        foreach ($reqList as $s) {
            if (!is_array($s)) continue;
            $m = trim((string) ($s["module"] ?? ""));
            if ($m === "") continue;
            $reqByMod[mb_strtolower($m)] = (int) ($s["seconds"] ?? 0);
        }

        // This session's live intervals, with ids/durations, grouped per module.
        $segStmt = $pdo->prepare("
            SELECT id, module_name, started_at,
                   TIMESTAMPDIFF(SECOND, started_at, ended_at) AS dur
            FROM study_segments
            WHERE user_id = ? AND session_id IS NULL AND ended_at IS NOT NULL
            ORDER BY module_name, started_at
        ");
        $segStmt->execute([$userId]);

        $byMod = []; // lower => ["module"=>name, "total"=>int, "segs"=>[rows]]
        foreach ($segStmt->fetchAll(PDO::FETCH_ASSOC) as $sr) {
            $mod = $sr["module_name"];
            if ($mod === null) continue; // un-mapped time isn't loggable
            $k = mb_strtolower($mod);
            if (!isset($byMod[$k])) {
                $byMod[$k] = ["module" => $mod, "total" => 0, "segs" => []];
            }
            $byMod[$k]["total"] += (int) $sr["dur"];
            $byMod[$k]["segs"][] = $sr;
        }

        $loggedSeconds = 0;
        $loggedCount   = 0;
        $loggedModules = [];
        foreach ($byMod as $k => $g) {
            if (!isset($reqByMod[$k])) continue; // module dropped (× in the modal)

            $actual = (int) $g["total"];
            $req    = (int) $reqByMod[$k];
            // 0/absent → keep the full amount; otherwise clamp into [1, actual].
            $target = $req <= 0 ? $actual : max(1, min($req, $actual));
            if ($target <= 0) continue;

            // Trim from the end to reach $target — drop whole trailing intervals,
            // then shorten the last partial one (so a forgotten tail disappears
            // from the recap too, not just from the logged total).
            $toRemove = $actual - $target;
            $segs = $g["segs"];
            for ($i = count($segs) - 1; $i >= 0 && $toRemove > 0; $i--) {
                $dur = (int) $segs[$i]["dur"];
                if ($dur <= $toRemove) {
                    $pdo->prepare("DELETE FROM study_segments WHERE id = ?")->execute([$segs[$i]["id"]]);
                    $toRemove -= $dur;
                } else {
                    $keep   = $dur - $toRemove;
                    $newEnd = (new DateTime($segs[$i]["started_at"]))
                        ->modify("+{$keep} seconds")->format("Y-m-d H:i:s");
                    $pdo->prepare("UPDATE study_segments SET ended_at = ? WHERE id = ?")
                        ->execute([$newEnd, $segs[$i]["id"]]);
                    $toRemove = 0;
                }
            }

            // started_at = the real sub-session start (of the surviving intervals).
            $startStmt = $pdo->prepare("
                SELECT MIN(started_at) FROM study_segments
                WHERE user_id = ? AND session_id IS NULL AND module_name = ? AND ended_at IS NOT NULL
            ");
            $startStmt->execute([$userId, $g["module"]]);
            $startAt = $startStmt->fetchColumn() ?: $row["session_start"];

            // at_library is carried over so the "library" podium filter can
            // replay it later.
            $pdo->prepare("
                INSERT INTO study_sessions (user_id, module_name, seconds, studied_on, started_at, at_library)
                VALUES (?, ?, ?, CURDATE(), ?, ?)
            ")->execute([$userId, $g["module"], $target, $startAt, (int) ($row["at_library"] ?? 0)]);

            // Attach this module's (trimmed) intervals to the new session.
            $sessionId = (int) $pdo->lastInsertId();
            $pdo->prepare("UPDATE study_segments SET session_id = ? WHERE user_id = ? AND session_id IS NULL AND module_name = ?")
                ->execute([$sessionId, $userId, $g["module"]]);

            $loggedSeconds += $target;
            $loggedCount++;
            $loggedModules[] = $g["module"];
        }

        // Drop any remaining unlogged intervals (un-mapped or dropped modules).
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
