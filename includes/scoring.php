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
