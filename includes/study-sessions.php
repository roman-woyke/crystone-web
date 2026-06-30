<?php

// Study-session aggregation shared by study-counter.php (initial paint) and
// api/get-study-data.php (background refresh). Both need the exact same shape:
//   [ ["username","module","date","seconds","at_library"], ... ]
// aggregated per user + module + day + at_library.
//
// Day attribution matches the recap timeline (includes/study-status.php): a
// "study day" runs 07:00 → 07:00 the next morning, and a session counts toward
// the day it *started* in — even if it ran past midnight. So an 8pm→2am night
// session lands wholly on the evening it began, not split across the calendar
// boundary and not bumped to the next day. The real start is `started_at`
// (session_start); manual duration entries have none, so they keep `studied_on`.

// The study day a timestamp belongs to: its own date, or the previous date when
// it's before 07:00 (still the previous evening's study day).
function studyDayOf(DateTime $dt): string
{
    $d = clone $dt;
    if ((int) $d->format("H") < 7) {
        $d->modify("-1 day");
    }
    return $d->format("Y-m-d");
}

// The day a session is charted on: its study day by real start time, falling
// back to the stored studied_on when there's no usable start (manual duration).
function studySessionDate(?string $startedAt, string $studiedOn): string
{
    if ($startedAt === null || $startedAt === "") {
        return $studiedOn;
    }
    try {
        return studyDayOf(new DateTime($startedAt));
    } catch (Exception $e) {
        return $studiedOn;
    }
}

// Fetch every session, bucket it onto its study day, and aggregate into the
// per-user/module/day/at_library shape the client expects.
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
        $date = studySessionDate($r["started_at"], $r["studied_on"]);
        $key  = $r["username"] . "\0" . $r["module_name"] . "\0" . $date . "\0" . (int) $r["at_library"];
        if (!isset($agg[$key])) {
            $agg[$key] = [
                "username"   => $r["username"],
                "module"     => $r["module_name"],
                "date"       => $date,
                "seconds"    => 0,
                "at_library" => (bool) $r["at_library"],
            ];
        }
        $agg[$key]["seconds"] += (int) $r["seconds"];
    }

    // Stable order by date (the client re-aggregates anyway, but keep it tidy).
    $rows = array_values($agg);
    usort($rows, fn($a, $b) => strcmp($a["date"], $b["date"]));
    return $rows;
}
