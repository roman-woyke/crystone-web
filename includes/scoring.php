<?php

$baseWeights = [
    "PENDING"  => 2,
    "REJECTED" => -1,
    "GHOSTED"  => -1,
];

$tagWeights = [
    "INTERVIEW" => [
        "MAYBE"           => 5,
        "PROBABLY"        => 7,
        "FOR SURE"        => 10,
        "ABSOLUTE CINEMA" => 15,
        ""                => 10,
    ],
    "OFFER" => [
        "MAYBE"           => 10,
        "PROBABLY"        => 14,
        "FOR SURE"        => 20,
        "ABSOLUTE CINEMA" => 30,
        ""                => 20,
    ],
];

function scorePoints(string $status, ?string $tag): int {
    global $baseWeights, $tagWeights;
    if (isset($baseWeights[$status])) return $baseWeights[$status];
    $tag = $tag ?? "";
    return $tagWeights[$status][$tag] ?? $tagWeights[$status][""];
}
