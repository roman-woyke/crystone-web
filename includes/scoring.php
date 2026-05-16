<?php

$baseWeights = [
    "PENDING"  => 2,
    "REJECTED" => -1,
    "GHOSTED"  => -1,
];

$tagWeights = [
    "INTERVIEW" => [
        "MAYBE"           => 5  - 2, // -2 because PENDING points are already counted
        "PROBABLY"        => 7  - 2,
        "FOR SURE"        => 10 - 2,
        "ABSOLUTE CINEMA" => 15 - 2,
        ""                => 10 - 2,
    ],
    "OFFER" => [
        "MAYBE"           => 10 - 2, // -2 because PENDING points are already counted
        "PROBABLY"        => 14 - 2,
        "FOR SURE"        => 20 - 2,
        "ABSOLUTE CINEMA" => 30 - 2,
        ""                => 20 - 2,
    ],
];

function scorePoints(string $status, ?string $tag): int {
    global $baseWeights, $tagWeights;
    if (isset($baseWeights[$status])) return $baseWeights[$status];
    $tag = $tag ?? "";
    return $tagWeights[$status][$tag] ?? $tagWeights[$status][""];
}

// Returns the SQL expression for the highest-priority status ever reached by an
// application. Requires a LEFT JOIN on application_status_history aliased as h.
// Priority: OFFER > INTERVIEW > PENDING > GHOSTED > REJECTED
function peakStatusSql(): string {
    return "CASE MAX(
            CASE h.status
                WHEN 'OFFER'     THEN 5
                WHEN 'INTERVIEW' THEN 4
                WHEN 'PENDING'   THEN 3
                WHEN 'GHOSTED'   THEN 2
                WHEN 'REJECTED'  THEN 1
                ELSE 0
            END
        )
            WHEN 5 THEN 'OFFER'
            WHEN 4 THEN 'INTERVIEW'
            WHEN 3 THEN 'PENDING'
            WHEN 2 THEN 'GHOSTED'
            WHEN 1 THEN 'REJECTED'
            ELSE NULL
        END AS peak_status";
}
