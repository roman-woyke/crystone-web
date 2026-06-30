<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

$userId = $_SESSION["user_id"];

// Clear the avatar (back to initials placeholder).
if (!empty($_POST["remove"])) {
    $pdo->prepare("UPDATE users SET avatar = NULL WHERE id = ?")->execute([$userId]);
    header("Content-Type: application/json");
    echo json_encode(["ok" => true, "avatar" => null]);
    exit;
}

// Otherwise store a base64 image data URL (resized client-side to a small
// square). We keep it in the DB so it works across all deployments without a
// writable uploads dir.
$avatar = $_POST["avatar"] ?? "";

if (!preg_match('#^data:image/(png|jpeg|jpg|webp);base64,#', $avatar, $m)) {
    http_response_code(400);
    exit("Invalid image.");
}

$base64 = substr($avatar, strpos($avatar, ",") + 1);
$binary = base64_decode($base64, true);
if ($binary === false) {
    http_response_code(400);
    exit("Invalid image data.");
}

// Cap the stored size (the client resizes before upload, so this is a backstop).
if (strlen($binary) > 400 * 1024) {
    http_response_code(413);
    exit("Image too large.");
}

// Confirm it really is an image of the claimed type.
$info = @getimagesizefromstring($binary);
if ($info === false) {
    http_response_code(400);
    exit("Not a valid image.");
}

$pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?")->execute([$avatar, $userId]);

header("Content-Type: application/json");
echo json_encode(["ok" => true, "avatar" => $avatar]);
