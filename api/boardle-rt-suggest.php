<?php

require_once __DIR__ . "/../includes/start-session.php";
require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../includes/boardle-rt.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Not logged in.");
}

boardleRtAdvance($pdo);
$row = boardleRtRow($pdo);
if ($row["phase"] !== "selecting") {
    http_response_code(409);
    exit("Not in the word-selection phase.");
}

header("Content-Type: application/json");
echo json_encode([
    "suggestions" => boardleRandomSuggestions((int) $row["word_length"], 3),
]);
