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
            IF(s.started_at IS NULL, TIMESTAMPDIFF(SECOND, s.updated_at, NOW()), 0) AS break_elapsed,
            s.at_library
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
            "at_library"    => (bool) $r["at_library"],
        ];
        $studying[] = $entry;

        if ((int) $r["user_id"] === (int) $userId) {
            $me = array_merge(["active" => true], $entry);
        }
    }

    // `me.parts` — my in-progress session broken down per module (one entry per
    // module sub-session), summing each module's live intervals up to now. The
    // stop checklist uses this to let me pick which parts to log. A null module
    // is the time studied before any module was assigned (not loggable).
    if ($me["active"]) {
        $p = $pdo->prepare("
            SELECT module_name,
                   SUM(TIMESTAMPDIFF(SECOND, started_at, COALESCE(ended_at, NOW()))) AS seconds
            FROM study_segments
            WHERE user_id = ? AND session_id IS NULL
            GROUP BY module_name
            ORDER BY MIN(started_at)
        ");
        $p->execute([$userId]);
        $parts = [];
        foreach ($p->fetchAll(PDO::FETCH_ASSOC) as $pr) {
            $parts[] = ["module" => $pr["module_name"], "seconds" => (int) $pr["seconds"]];
        }
        $me["parts"] = $parts;
    }

    // `recap` is the current study day's timeline; `recap_prev` is the previous
    // study day's, so the dock can flip to a "Gestern" (yesterday) view.
    return [
        "me"        => $me,
        "studying"  => $studying,
        "recap"     => studyRecap($pdo, 0),
        "recap_prev"=> studyRecap($pdo, 1),
    ];
}

// Build one study day's recap timeline, $daysBack study days before the current
// one (0 = today's study day, 1 = the previous one).
//
// The study day starts at 07:00, so before 07:00 we're still on yesterday's day
// and post-midnight work (e.g. a 01:00 session) counts toward it. $base is that
// day's midnight (today's, or yesterday's when it's before 07:00) shifted back
// $daysBack days — positions stay minutes-from-midnight so the client maps them
// onto its 07:00 → 06:00 axis; $winStart/$winEnd bound the 24h study-day window
// used for inclusion (segments are matched by their real timestamp so a session
// that crossed midnight stays on the day it started).
//   1) logged segments (the real intervals of finished sessions)
//   2) sessions with no segments (manual entries / pre-migration) → one block
//   3) live segments of an in-progress session (the open one is `live`) — only
//      on the current study day, since they're happening right now.
function studyRecap(PDO $pdo, int $daysBack = 0): array
{
    $base     = "(IF(CURTIME() < '07:00:00', CURDATE() - INTERVAL 1 DAY, CURDATE()) - INTERVAL $daysBack DAY)";
    $winStart = "($base + INTERVAL 7 HOUR)";   // 07:00 of the study day
    $winEnd   = "($base + INTERVAL 31 HOUR)";  // 07:00 the next morning

    $liveUnion = $daysBack === 0 ? "
            UNION ALL

            SELECT
                u.username,
                seg.module_name AS module,
                TIMESTAMPDIFF(MINUTE, $base, seg.started_at) AS start_min,
                TIMESTAMPDIFF(MINUTE, $base, COALESCE(seg.ended_at, NOW())) AS end_min,
                TIMESTAMPDIFF(SECOND, seg.started_at, COALESCE(seg.ended_at, NOW())) AS seconds,
                (seg.ended_at IS NULL) AS live,
                seg.started_at AS sort_at
            FROM study_segments seg
            JOIN users u ON u.id = seg.user_id
            WHERE seg.session_id IS NULL
    " : "";

    $recapRows = $pdo->query("
        SELECT username, module, start_min, end_min, seconds, live, sort_at FROM (
            SELECT
                u.username,
                ss.module_name AS module,
                TIMESTAMPDIFF(MINUTE, $base, seg.started_at) AS start_min,
                TIMESTAMPDIFF(MINUTE, $base, seg.ended_at)   AS end_min,
                TIMESTAMPDIFF(SECOND, seg.started_at, seg.ended_at) AS seconds,
                0 AS live,
                seg.started_at AS sort_at
            FROM study_segments seg
            JOIN study_sessions ss ON ss.id = seg.session_id
            JOIN users u ON u.id = ss.user_id
            WHERE seg.started_at >= $winStart AND seg.started_at < $winEnd
              AND seg.ended_at IS NOT NULL

            UNION ALL

            SELECT
                u.username,
                ss.module_name AS module,
                TIMESTAMPDIFF(MINUTE, $base, COALESCE(ss.started_at, ss.created_at - INTERVAL ss.seconds SECOND)) AS start_min,
                TIMESTAMPDIFF(MINUTE, $base, ss.created_at) AS end_min,
                ss.seconds AS seconds,
                0 AS live,
                COALESCE(ss.started_at, ss.created_at - INTERVAL ss.seconds SECOND) AS sort_at
            FROM study_sessions ss
            JOIN users u ON u.id = ss.user_id
            WHERE ss.studied_on = ($base)
              AND NOT EXISTS (SELECT 1 FROM study_segments x WHERE x.session_id = ss.id)
            $liveUnion
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

    return $recap;
}
