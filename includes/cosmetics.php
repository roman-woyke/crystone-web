<?php

// Cosmetics registry: item_key -> {label, file}. `file` is the PNG in
// assets/images/. Adding a new cosmetic later just means adding a row here
// and dropping the asset in — ensureCosmeticsGranted() picks it up for
// everyone the next time they open the Hats tab.
const COSMETICS = [
    "wizard-hat"    => ["label" => "Wizard Hat",    "file" => "cartoon_wizard_hat.png"],
    "graduate-hat"  => ["label" => "Graduate Cap",  "file" => "graduate-hat.png"],
    "jester-hat"    => ["label" => "Jester Hat",    "file" => "jester-hat.png"],
    "commander-hat" => ["label" => "Commander Hat", "file" => "commander-hat.png"],
    "military-hat"  => ["label" => "Military Hat",  "file" => "military-hat.png"],
];

// Placement bounds — keep the hat inside the "universal box" above the avatar.
const COSMETIC_BOUNDS = [
    "offset_x" => [-40.0, 40.0],
    "offset_y" => [-40.0, 40.0],
    "rotation" => [-45.0, 45.0],
    "scale"    => [0.7, 1.5],
];

function cosmeticClamp(string $field, float $value): float {
    [$min, $max] = COSMETIC_BOUNDS[$field];
    return max($min, min($max, $value));
}

// Grants every registered cosmetic to a user (INSERT IGNORE — a no-op for
// ones already owned), so newly added assets reach existing users lazily.
function ensureCosmeticsGranted(PDO $pdo, int $userId): void {
    $stmt = $pdo->prepare("INSERT IGNORE INTO user_cosmetics (user_id, item_key) VALUES (?, ?)");
    foreach (array_keys(COSMETICS) as $key) {
        $stmt->execute([$userId, $key]);
    }
}

// username -> equipped hat's render data, for the study-counter podium.
function getEquippedHats(PDO $pdo): array {
    $rows = $pdo->query("
        SELECT u.username, c.item_key, c.offset_x, c.offset_y, c.rotation, c.scale, c.flip_x
        FROM user_cosmetics c
        JOIN users u ON u.id = c.user_id
        WHERE c.equipped = 1
    ")->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        if (!isset(COSMETICS[$r["item_key"]])) continue;
        $out[$r["username"]] = [
            "item_key" => $r["item_key"],
            "file"     => COSMETICS[$r["item_key"]]["file"],
            "offset_x" => (float) $r["offset_x"],
            "offset_y" => (float) $r["offset_y"],
            "rotation" => (float) $r["rotation"],
            "scale"    => (float) $r["scale"],
            "flip_x"   => (bool) $r["flip_x"],
        ];
    }
    return $out;
}
