<?php
require_once __DIR__ . "/../scripts/env.php";
loadEnv(__DIR__ . "/../.env");

$pdo = new PDO(
    "mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['DB_NAME'] . ";charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

header('Content-Type: application/json');

$id     = $_POST['id']     ?? null;
$action = $_POST['action'] ?? null;

if (!$id || !$action) {
    http_response_code(400);
    echo json_encode(["error" => "Missing data"]);
    exit;
}

if ($action === "reject") {
    $pdo->prepare("UPDATE publish_queue SET status='rejected' WHERE id=?")->execute([$id]);
    echo json_encode(["ok" => true]);
    exit;
}

if ($action === "publish") {
    $targets = json_decode($_POST['targets'] ?? '[]', true);

    $pdo->prepare("UPDATE publish_queue SET status='approved' WHERE id=?")->execute([$id]);

    $stmt = $pdo->prepare("SELECT draft_id FROM publish_queue WHERE id=?");
    $stmt->execute([$id]);
    $draft_id = $stmt->fetchColumn();
    if ($draft_id) {
        $pdo->prepare("UPDATE ai_drafts SET status='published', published_at=NOW() WHERE id=?")->execute([$draft_id]);
    }

    foreach ($targets as $t) {
        $pdo->prepare("INSERT INTO publish_targets (queue_id, target) VALUES (?,?)")->execute([$id, $t]);
    }

    echo json_encode(["ok" => true]);
    exit;
}

http_response_code(400);
echo json_encode(["error" => "Invalid action"]);
