<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

$userId = $_SESSION["user_id"];

$module    = trim($_POST["module"]     ?? "");
$newModule = trim($_POST["new_module"] ?? "");
$startTime = trim($_POST["start_time"] ?? "");
$endTime   = trim($_POST["end_time"]   ?? "");

$startedAt = null; // set in exact (start/end) mode → also written as a segment
$endedAt   = null;

if ($startTime !== "" && $endTime !== "") {
    // ── Exact mode: a start & end time for today ─────────────────────────────
    $today     = (new DateTime("today"))->format("Y-m-d");
    $studiedOn = $today;

    if (!preg_match('/^\d{1,2}:\d{2}$/', $startTime) || !preg_match('/^\d{1,2}:\d{2}$/', $endTime)) {
        http_response_code(400);
        exit("Invalid time.");
    }
    $startDT = DateTime::createFromFormat("!Y-m-d H:i", "$today $startTime");
    $endDT   = DateTime::createFromFormat("!Y-m-d H:i", "$today $endTime");
    if (!$startDT || !$endDT) {
        http_response_code(400);
        exit("Invalid time.");
    }

    $seconds = $endDT->getTimestamp() - $startDT->getTimestamp();
    if ($seconds <= 0) {
        http_response_code(400);
        exit("End time must be after the start time.");
    }
    if ($seconds > 86400) {
        http_response_code(400);
        exit("Invalid duration.");
    }

    $startedAt = $startDT->format("Y-m-d H:i:s");
    $endedAt   = $endDT->format("Y-m-d H:i:s");
} else {
    // ── Duration mode: a length + a date (today or earlier) ──────────────────
    $seconds   = filter_var($_POST["seconds"] ?? null, FILTER_VALIDATE_INT);
    $studiedOn = trim($_POST["studied_on"] ?? "");

    if ($seconds === false || $seconds === null || $seconds <= 0 || $seconds > 86400) {
        http_response_code(400);
        exit("Invalid duration.");
    }

    if ($studiedOn === "") {
        $studiedOn = (new DateTime("today"))->format("Y-m-d");
    } else {
        // "!Y-m-d" resets the time to 00:00:00 — otherwise today's date keeps the
        // current clock time and wrongly compares as "in the future".
        $d = DateTime::createFromFormat("!Y-m-d", $studiedOn);
        if (!$d || $d->format("Y-m-d") !== $studiedOn) {
            http_response_code(400);
            exit("Invalid date.");
        }
        if ($d > new DateTime("today")) {
            http_response_code(400);
            exit("Date cannot be in the future.");
        }
    }
}

// ── Allowed modules: exam titles + existing custom modules ───────────────
$examTitles = $pdo->query("SELECT DISTINCT title FROM exams")->fetchAll(PDO::FETCH_COLUMN);
$customMods = $pdo->query("SELECT name FROM study_modules")->fetchAll(PDO::FETCH_COLUMN);
$allowed = array_merge($examTitles, $customMods);

// ── Resolve the final module name ────────────────────────────────────────
$finalModule = null;
$isCustom    = false;

if ($newModule !== "") {
    if (mb_strlen($newModule) > 255) {
        http_response_code(400);
        exit("Module name too long.");
    }

    // Reuse an existing module if the name already exists (case-insensitive).
    foreach ($allowed as $m) {
        if (strcasecmp($m, $newModule) === 0) {
            $finalModule = $m;
            break;
        }
    }

    if ($finalModule === null) {
        $pdo->prepare("INSERT INTO study_modules (name, created_by) VALUES (?, ?)")
            ->execute([$newModule, $userId]);
        $finalModule = $newModule;
        $isCustom    = true;
    } else {
        // Existing name — flag custom if it isn't one of the exam titles.
        $isCustom = !in_array($finalModule, $examTitles, true);
    }
} else {
    foreach ($allowed as $m) {
        if (strcasecmp($m, $module) === 0) {
            $finalModule = $m;
            break;
        }
    }
    if ($finalModule === null) {
        http_response_code(400);
        exit("Unknown module.");
    }
    $isCustom = !in_array($finalModule, $examTitles, true);
}

// ── Store the session ────────────────────────────────────────────────────
$pdo->prepare("
    INSERT INTO study_sessions (user_id, module_name, seconds, studied_on, started_at)
    VALUES (?, ?, ?, ?, ?)
")->execute([$userId, $finalModule, $seconds, $studiedOn, $startedAt]);

// Exact mode → store the interval as a segment so the day recap draws it at the
// real clock times (duration mode has no fixed times, so no segment).
if ($startedAt !== null) {
    $sessionId = (int) $pdo->lastInsertId();
    $pdo->prepare("
        INSERT INTO study_segments (user_id, session_id, started_at, ended_at)
        VALUES (?, ?, ?, ?)
    ")->execute([$userId, $sessionId, $startedAt, $endedAt]);
}

header("Content-Type: application/json");
echo json_encode([
    "ok"      => true,
    "module"  => $finalModule,
    "custom"  => $isCustom,
    "seconds" => $seconds,
    "date"    => $studiedOn,
]);
