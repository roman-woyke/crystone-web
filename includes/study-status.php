<?php

// Shared helper for the study-counter presence/timer feature.
//
// The `study_status` table holds at most one row per user describing their
// current study state:
//   - mode='timer'    → a persistent stopwatch (survives page reloads). Elapsed
//                       seconds = accumulated + (running ? now - started_at : 0).
//   - mode='presence' → a manual "I'm studying" flag with no stopwatch.
//
// A row existing at all means the user is "currently studying".

function studyStatusPayload(PDO $pdo, $userId): array
{
    // While paused, the row isn't touched, so `updated_at` marks when the break
    // began — `break_elapsed` is the time since then.
    $rows = $pdo->query("
        SELECT
            u.id AS user_id,
            u.username,
            s.mode,
            s.module_name,
            s.accumulated + IF(s.started_at IS NULL, 0, TIMESTAMPDIFF(SECOND, s.started_at, NOW())) AS elapsed,
            (s.started_at IS NOT NULL) AS running,
            IF(s.started_at IS NULL, TIMESTAMPDIFF(SECOND, s.updated_at, NOW()), 0) AS break_elapsed
        FROM study_status s
        JOIN users u ON u.id = s.user_id
        ORDER BY u.username
    ")->fetchAll(PDO::FETCH_ASSOC);

    $studying = [];
    $me = ["active" => false];

    foreach ($rows as $r) {
        $entry = [
            "username"      => $r["username"],
            "mode"          => $r["mode"],
            "module"        => $r["module_name"],
            "elapsed"       => (int) $r["elapsed"],
            "running"       => (bool) $r["running"],
            "break_elapsed" => (int) $r["break_elapsed"],
        ];
        $studying[] = $entry;

        if ((int) $r["user_id"] === (int) $userId) {
            $me = array_merge(["active" => true], $entry);
        }
    }

    // ── Today's recap: every session logged today, with its time window ──────
    // `created_at` is when the session was recorded (≈ when it ended), so the
    // window is [created_at - seconds, created_at].
    $recapRows = $pdo->query("
        SELECT
            u.username,
            s.module_name,
            s.seconds,
            DATE_FORMAT(s.created_at - INTERVAL s.seconds SECOND, '%H:%i') AS start_time,
            DATE_FORMAT(s.created_at, '%H:%i') AS end_time
        FROM study_sessions s
        JOIN users u ON u.id = s.user_id
        WHERE s.studied_on = CURDATE()
        ORDER BY s.created_at
    ")->fetchAll(PDO::FETCH_ASSOC);

    $recap = [];
    foreach ($recapRows as $r) {
        $recap[] = [
            "username" => $r["username"],
            "module"   => $r["module_name"],
            "seconds"  => (int) $r["seconds"],
            "start"    => $r["start_time"],
            "end"      => $r["end_time"],
        ];
    }

    return ["me" => $me, "studying" => $studying, "recap" => $recap];
}
