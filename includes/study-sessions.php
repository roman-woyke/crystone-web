<?php

// Study-session aggregation shared by study-counter.php (initial paint) and
// api/get-study-data.php (background refresh). Both need the exact same shape:
//   [ ["username","module","date","seconds","at_library"], ... ]
// aggregated per user + module + day + at_library.
//
// Day attribution: a session is split across calendar days by its real
// wall-clock window. Timer/presence rows carry `started_at` (the true session
// start); a session that runs past midnight is divided so each day only counts
// the seconds actually spent on it (e.g. an 8pm→2am run shows 4h on the start
// day and 2h on the next), instead of dumping the whole block on the log date.
// Manual duration rows have no `started_at`, so they stay on `studied_on`.

// Split one session's seconds across the days its [start, start+seconds] window
// covers. Returns ["Y-m-d" => seconds]. Falls back to the whole block on
// $studiedOn when there's no usable start time.
function splitStudySessionByDay(?string $startedAt, string $studiedOn, int $seconds): array
{
    if ($startedAt === null || $startedAt === "" || $seconds <= 0) {
        return [$studiedOn => $seconds];
    }

    try {
        $start = new DateTime($startedAt);
    } catch (Exception $e) {
        return [$studiedOn => $seconds];
    }

    $end = (clone $start)->modify("+{$seconds} seconds");
    $out = [];
    $cursor = clone $start;

    while ($cursor < $end) {
        // Midnight that ends the cursor's day.
        $nextMidnight = (clone $cursor)->setTime(0, 0, 0)->modify("+1 day");
        $sliceEnd = $nextMidnight < $end ? $nextMidnight : $end;

        $secs = $sliceEnd->getTimestamp() - $cursor->getTimestamp();
        if ($secs > 0) {
            $key = $cursor->format("Y-m-d");
            $out[$key] = ($out[$key] ?? 0) + $secs;
        }
        $cursor = $sliceEnd;
    }

    return $out ?: [$studiedOn => $seconds];
}

// Fetch every session, split overnight ones across days, and re-aggregate into
// the per-user/module/day/at_library shape the client expects.
function studySessionsByDay(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT u.username, s.module_name, s.studied_on, s.started_at, s.at_library, s.seconds
        FROM study_sessions s
        JOIN users u ON u.id = s.user_id
        ORDER BY s.studied_on
    ");

    $agg = []; // key => row
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $perDay = splitStudySessionByDay($r["started_at"], $r["studied_on"], (int) $r["seconds"]);
        foreach ($perDay as $date => $secs) {
            $key = $r["username"] . "\0" . $r["module_name"] . "\0" . $date . "\0" . (int) $r["at_library"];
            if (!isset($agg[$key])) {
                $agg[$key] = [
                    "username"   => $r["username"],
                    "module"     => $r["module_name"],
                    "date"       => $date,
                    "seconds"    => 0,
                    "at_library" => (bool) $r["at_library"],
                ];
            }
            $agg[$key]["seconds"] += $secs;
        }
    }

    // Stable order by date (the client re-aggregates anyway, but keep it tidy).
    $rows = array_values($agg);
    usort($rows, fn($a, $b) => strcmp($a["date"], $b["date"]));
    return $rows;
}
