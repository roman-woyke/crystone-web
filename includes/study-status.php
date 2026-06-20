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
//
// `study_segments` records one row per contiguous study interval (start/resume
// opens one, pause/log closes it), so the day recap can draw the real intervals
// with breaks shown as the gaps between them.

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

    // ── Today's recap: one block per study INTERVAL (breaks are the gaps) ────
    // Positions are minutes from today's midnight (CURDATE()); a block whose
    // interval started yesterday comes out negative and is clamped client-side.
    //   1) logged segments (the real intervals of finished sessions)
    //   2) sessions with no segments (manual entries / pre-migration) → one block
    //   3) live segments of an in-progress session (the open one is `live`)
    $recapRows = $pdo->query("
        SELECT username, module, start_min, end_min, seconds, live, sort_at FROM (
            SELECT
                u.username,
                ss.module_name AS module,
                TIMESTAMPDIFF(MINUTE, CURDATE(), seg.started_at) AS start_min,
                TIMESTAMPDIFF(MINUTE, CURDATE(), seg.ended_at)   AS end_min,
                TIMESTAMPDIFF(SECOND, seg.started_at, seg.ended_at) AS seconds,
                0 AS live,
                seg.started_at AS sort_at
            FROM study_segments seg
            JOIN study_sessions ss ON ss.id = seg.session_id
            JOIN users u ON u.id = ss.user_id
            WHERE ss.studied_on = CURDATE() AND seg.ended_at IS NOT NULL

            UNION ALL

            SELECT
                u.username,
                ss.module_name AS module,
                TIMESTAMPDIFF(MINUTE, CURDATE(), COALESCE(ss.started_at, ss.created_at - INTERVAL ss.seconds SECOND)) AS start_min,
                TIMESTAMPDIFF(MINUTE, CURDATE(), ss.created_at) AS end_min,
                ss.seconds AS seconds,
                0 AS live,
                COALESCE(ss.started_at, ss.created_at - INTERVAL ss.seconds SECOND) AS sort_at
            FROM study_sessions ss
            JOIN users u ON u.id = ss.user_id
            WHERE ss.studied_on = CURDATE()
              AND NOT EXISTS (SELECT 1 FROM study_segments x WHERE x.session_id = ss.id)

            UNION ALL

            SELECT
                u.username,
                st.module_name AS module,
                TIMESTAMPDIFF(MINUTE, CURDATE(), seg.started_at) AS start_min,
                TIMESTAMPDIFF(MINUTE, CURDATE(), COALESCE(seg.ended_at, NOW())) AS end_min,
                TIMESTAMPDIFF(SECOND, seg.started_at, COALESCE(seg.ended_at, NOW())) AS seconds,
                (seg.ended_at IS NULL) AS live,
                seg.started_at AS sort_at
            FROM study_segments seg
            JOIN study_status st ON st.user_id = seg.user_id
            JOIN users u ON u.id = seg.user_id
            WHERE seg.session_id IS NULL
        ) blocks
        ORDER BY sort_at
    ")->fetchAll(PDO::FETCH_ASSOC);

    $recap = [];
    foreach ($recapRows as $r) {
        $recap[] = [
            "username"  => $r["username"],
            "module"    => $r["module"],
            "start_min" => (int) $r["start_min"],
            "end_min"   => (int) $r["end_min"],
            "seconds"   => (int) $r["seconds"],
            "live"      => (bool) $r["live"],
        ];
    }

    return ["me" => $me, "studying" => $studying, "recap" => $recap];
}
