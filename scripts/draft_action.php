<?php
require_once __DIR__ . "/env.php";
loadEnv(__DIR__ . "/../.env");

$pdo = new PDO(
    "mysql:host=".$_ENV['DB_HOST'].";dbname=".$_ENV['DB_NAME'].";charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS']
);

$id = $_POST['id'] ?? null;
$action = $_POST['action'] ?? null;
$content = $_POST['content'] ?? null;

if (!$id || !$action) {
    http_response_code(400);
    echo json_encode(["error" => "Missing data"]);
    exit;
}

switch ($action) {

    case "approve":
        $stmt = $pdo->prepare("UPDATE ai_drafts SET status='approved' WHERE id=?");
        $stmt->execute([$id]);
        echo json_encode(["status" => "approved"]);
        break;

    case "reject":
        $stmt = $pdo->prepare("UPDATE ai_drafts SET status='rejected' WHERE id=?");
        $stmt->execute([$id]);
        echo json_encode(["status" => "rejected"]);
        break;

    case "save":
        $stmt = $pdo->prepare("UPDATE ai_drafts SET content=? WHERE id=?");
        $stmt->execute([$content, $id]);
        echo json_encode(["status" => "saved"]);
        break;

    default:
        http_response_code(400);
        echo json_encode(["error" => "Invalid action"]);
}
