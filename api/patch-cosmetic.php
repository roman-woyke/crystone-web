<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../includes/cosmetics.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

$userId  = (int) $_SESSION["user_id"];
$itemKey = $_POST["item_key"] ?? "";

if (!isset(COSMETICS[$itemKey])) {
    http_response_code(400);
    exit("Unknown cosmetic.");
}

ensureCosmeticsGranted($pdo, $userId);

// Equip / unequip — only one hat can be equipped at a time.
if (isset($_POST["equip"])) {
    $equip = $_POST["equip"] === "1";
    if ($equip) {
        $pdo->prepare("UPDATE user_cosmetics SET equipped = 0 WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("UPDATE user_cosmetics SET equipped = 1 WHERE user_id = ? AND item_key = ?")
            ->execute([$userId, $itemKey]);
    } else {
        $pdo->prepare("UPDATE user_cosmetics SET equipped = 0 WHERE user_id = ? AND item_key = ?")
            ->execute([$userId, $itemKey]);
    }
}

// Placement update — any subset of these may be present.
$fields = [];
$values = [];
foreach (["offset_x", "offset_y", "rotation", "scale"] as $field) {
    if (isset($_POST[$field]) && is_numeric($_POST[$field])) {
        $fields[] = "$field = ?";
        $values[] = cosmeticClamp($field, (float) $_POST[$field]);
    }
}
if (isset($_POST["flip_x"])) {
    $fields[] = "flip_x = ?";
    $values[] = $_POST["flip_x"] === "1" ? 1 : 0;
}
if ($fields) {
    $values[] = $userId;
    $values[] = $itemKey;
    $pdo->prepare("UPDATE user_cosmetics SET " . implode(", ", $fields) . " WHERE user_id = ? AND item_key = ?")
        ->execute($values);
}

$stmt = $pdo->prepare("
    SELECT item_key, equipped, offset_x, offset_y, rotation, scale, flip_x
    FROM user_cosmetics WHERE user_id = ? AND item_key = ?
");
$stmt->execute([$userId, $itemKey]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

header("Content-Type: application/json");
echo json_encode([
    "ok" => true,
    "cosmetic" => [
        "item_key" => $itemKey,
        "label"    => COSMETICS[$itemKey]["label"],
        "file"     => COSMETICS[$itemKey]["file"],
        "equipped" => (bool) $row["equipped"],
        "offset_x" => (float) $row["offset_x"],
        "offset_y" => (float) $row["offset_y"],
        "rotation" => (float) $row["rotation"],
        "scale"    => (float) $row["scale"],
        "flip_x"   => (bool) $row["flip_x"],
    ],
]);
