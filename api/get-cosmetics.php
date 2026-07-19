<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../includes/cosmetics.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

$userId = (int) $_SESSION["user_id"];
ensureCosmeticsGranted($pdo, $userId);

$stmt = $pdo->prepare("
    SELECT item_key, equipped, offset_x, offset_y, rotation, scale, flip_x
    FROM user_cosmetics
    WHERE user_id = ?
");
$stmt->execute([$userId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$byKey = [];
foreach ($rows as $r) $byKey[$r["item_key"]] = $r;

$owned = [];
foreach (COSMETICS as $key => $meta) {
    $r = $byKey[$key] ?? null;
    $owned[] = [
        "item_key" => $key,
        "label"    => $meta["label"],
        "file"     => $meta["file"],
        "equipped" => $r ? (bool) $r["equipped"] : false,
        "offset_x" => $r ? (float) $r["offset_x"] : 0.0,
        "offset_y" => $r ? (float) $r["offset_y"] : 0.0,
        "rotation" => $r ? (float) $r["rotation"] : 0.0,
        "scale"    => $r ? (float) $r["scale"] : 1.0,
        "flip_x"   => $r ? (bool) $r["flip_x"] : false,
    ];
}

header("Content-Type: application/json");
echo json_encode(["ok" => true, "owned" => $owned]);
