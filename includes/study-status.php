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

// Fold an interruption shorter than $minSecs into the run before it instead
// of letting it fragment — or silently drop time from — what's really one
// continuous stretch: a short break taken mid-module (or a short module
// check taken mid-break) doesn't end that run, it's absorbed into it.
//
// $items is chronological: each ["type" => "module"|"break",
// "module" => ?string, "start" => "Y-m-d H:i:s", "end" => ?string (null =
// still open/live)]. Consecutive items of the same type (and, for modules,
// the same name) always merge into one run regardless of how short either
// side is — merging never depends on the *incoming* item's own size, only on
// whether it matches the run it would extend, which is what makes a
// previously-live run that's just closed (and turned out short on its own)
// keep counting as part of the bigger run it was folded into, rather than
// resetting. An item that doesn't match the run before it (or has nothing
// before it, e.g. a short module tap that opens the session before the first
// break) only survives on its own if it's substantial or still live —
// otherwise it's remembered as a pending gap and backdated onto whichever
// run comes *next*, of any type, so its span still ends up counted somewhere
// instead of vanishing between two runs it didn't match either side of.
function studyMergeRuns(array $items, string $now, int $minSecs = 300): array
{
    $out = [];
    // Start of a filler run that hasn't found a home yet — e.g. a session
    // that opens with a 2-minute module tap before the first break has
    // nothing of matching type before it to extend, so on its own it would
    // just vanish, taking those 2 minutes with it. Instead we remember where
    // it began and backdate the *next* accepted run (of any type) to start
    // there too, so its span still ends up counted somewhere.
    $pendingStart = null;

    foreach ($items as $it) {
        $isLive = $it["end"] === null;
        $secs = $isLive
            ? (strtotime($now) - strtotime($it["start"]))
            : (strtotime($it["end"]) - strtotime($it["start"]));

        $lastIdx  = count($out) - 1;
        $sameRun  = $lastIdx >= 0 && !$out[$lastIdx]["live"] && (
            ($out[$lastIdx]["type"] === "module" && $it["type"] === "module" && $out[$lastIdx]["module"] === $it["module"]) ||
            ($out[$lastIdx]["type"] === "break"  && $it["type"] === "break")
        );

        if ($sameRun) {
            $out[$lastIdx]["end"]  = $it["end"];
            $out[$lastIdx]["live"] = $isLive;
            $pendingStart = null;
            continue;
        }

        if ($isLive || $secs >= $minSecs) {
            if ($pendingStart !== null) {
                $it["start"]  = $pendingStart;
                $pendingStart = null;
            }
            $it["live"] = $isLive;
            $out[] = $it;
        } elseif ($pendingStart === null) {
            $pendingStart = $it["start"];
        }
    }

    foreach ($out as &$r) {
        $r["seconds"] = $r["end"] !== null
            ? (strtotime($r["end"]) - strtotime($r["start"]))
            : (strtotime($now) - strtotime($r["start"]));
    }
    unset($r);

    return $out;
}

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

    $studyingByUser = [];
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
            "parts"         => [],
        ];
        $studyingByUser[(int) $r["user_id"]] = $entry;

        if ((int) $r["user_id"] === (int) $userId) {
            $me = array_merge(["active" => true], $entry);
        }
    }

    // `parts` — every active user's in-progress session broken down into its
    // full chronological history: one entry per module run *and* one per
    // break in between, in the order they actually happened. A module
    // switched away from and back to (X -> Y -> X) shows as three separate
    // lines rather than collapsing X's two intervals into one — and a break
    // taken mid-session shows as its own "Break" line instead of vanishing.
    // Interruptions under 5 minutes are folded into the run they interrupted
    // via studyMergeRuns() (see there for why), so a brief break doesn't
    // fragment a module's line, and vice versa — the stop checklist reads
    // `me.parts` directly, so this is also what ends up logged. A null
    // module is the time studied before any module was assigned (not
    // loggable).
    if ($studyingByUser) {
        $now = $pdo->query("SELECT NOW()")->fetchColumn();

        // Pre-assignment intervals (module_name NULL) fall back to that
        // user's session's current module, so they don't show up as
        // orphaned time.
        $segStmt = $pdo->query("
            SELECT seg.user_id,
                   COALESCE(seg.module_name, st.module_name) AS module,
                   seg.started_at,
                   seg.ended_at
            FROM study_segments seg
            JOIN study_status st ON st.user_id = seg.user_id
            WHERE seg.session_id IS NULL
            ORDER BY seg.user_id, seg.started_at
        ");

        $segsByUser = [];
        foreach ($segStmt->fetchAll(PDO::FETCH_ASSOC) as $sr) {
            $segsByUser[(int) $sr["user_id"]][] = $sr;
        }

        foreach ($segsByUser as $uid => $segs) {
            if (!isset($studyingByUser[$uid])) continue;
            $items   = [];
            $prevEnd = null; // ended_at of the previous closed segment, for gap detection

            foreach ($segs as $seg) {
                if ($prevEnd !== null && $seg["started_at"] > $prevEnd) {
                    $items[] = ["type" => "break", "module" => null, "start" => $prevEnd, "end" => $seg["started_at"]];
                }
                $isLive = $seg["ended_at"] === null;
                $items[] = ["type" => "module", "module" => $seg["module"], "start" => $seg["started_at"], "end" => $seg["ended_at"]];
                $prevEnd = $isLive ? null : $seg["ended_at"];
            }

            // A break currently in progress (paused, not yet resumed) has no
            // closing segment to anchor it — it runs from the last close to now.
            if ($prevEnd !== null && !$studyingByUser[$uid]["running"]) {
                $items[] = ["type" => "break", "module" => null, "start" => $prevEnd, "end" => null];
            }

            $parts = array_map(
                fn($r) => ["type" => $r["type"], "module" => $r["module"], "seconds" => $r["seconds"], "live" => $r["live"]],
                studyMergeRuns($items, $now)
            );

            $studyingByUser[$uid]["parts"] = $parts;

            // The top-level break_elapsed (used outside the breakdown, e.g.
            // the dock's simple "on break" state) should agree with the
            // merged live break line rather than only counting time since
            // the *last* pause — otherwise a short module tap mid-break
            // would make this number jump backward even though the
            // breakdown correctly kept counting through it.
            $liveBreak = end($parts);
            if ($liveBreak && $liveBreak["type"] === "break" && $liveBreak["live"]) {
                $studyingByUser[$uid]["break_elapsed"] = $liveBreak["seconds"];
                if ($uid === (int) $userId) $me["break_elapsed"] = $liveBreak["seconds"];
            }
        }

        if (isset($studyingByUser[(int) $userId])) {
            $me["parts"] = $studyingByUser[(int) $userId]["parts"];
        }
    }

    $studying = array_values($studyingByUser);

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
                COALESCE(seg.module_name, st.module_name) AS module,
                TIMESTAMPDIFF(MINUTE, $base, seg.started_at) AS start_min,
                TIMESTAMPDIFF(MINUTE, $base, COALESCE(seg.ended_at, NOW())) AS end_min,
                TIMESTAMPDIFF(SECOND, seg.started_at, COALESCE(seg.ended_at, NOW())) AS seconds,
                (seg.ended_at IS NULL) AS live,
                seg.started_at AS sort_at
            FROM study_segments seg
            JOIN study_status st ON st.user_id = seg.user_id
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

    return studyMergeRecapBlocks($recap);
}

// Companion to studyMergeRuns() for the recap timeline, which only ever draws
// module blocks (breaks are just the whitespace between them, nothing to
// merge on their own). Two consecutive blocks of the *same* module for the
// *same* user, separated by a gap under 5 minutes, are joined into one —
// same reasoning as studyMergeRuns(): a short break shouldn't visually
// fragment (or drop the time from) what's really one continuous stretch.
function studyMergeRecapBlocks(array $recap, int $minGapMin = 5): array
{
    $byUser = [];
    foreach ($recap as $r) {
        $byUser[$r["username"]][] = $r;
    }

    $out = [];
    foreach ($byUser as $rows) {
        $merged = [];
        foreach ($rows as $r) {
            $lastIdx = count($merged) - 1;
            if ($lastIdx >= 0
                && !$merged[$lastIdx]["live"]
                && $merged[$lastIdx]["module"] !== null
                && $merged[$lastIdx]["module"] === $r["module"]
                && ($r["start_min"] - $merged[$lastIdx]["end_min"]) < $minGapMin
            ) {
                $merged[$lastIdx]["seconds"] += ($r["start_min"] - $merged[$lastIdx]["end_min"]) * 60 + $r["seconds"];
                $merged[$lastIdx]["end_min"]  = $r["end_min"];
                $merged[$lastIdx]["live"]     = $r["live"];
            } else {
                $merged[] = $r;
            }
        }
        foreach ($merged as $m) $out[] = $m;
    }

    return $out;
}
