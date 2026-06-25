<?php
require_once __DIR__ . '/../scripts/auth.php';
requireAuth();
verifyCsrf();
require_once __DIR__ . "/../scripts/env.php";
loadEnv(__DIR__ . "/../.env");

require_once __DIR__ . "/../scripts/content_safety.php";

$pdo = new PDO(
    "mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['DB_NAME'] . ";charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

header('Content-Type: application/json');

$id      = $_POST['id']      ?? null;
$action  = $_POST['action']  ?? null;
$content = $_POST['content'] ?? null;

if (!$id || !$action) {
    http_response_code(400);
    echo json_encode(["error" => "Missing data"]);
    exit;
}

switch ($action) {

    case "approve":
        $dq = $pdo->prepare("SELECT content, type FROM ai_drafts WHERE id=?");
        $dq->execute([$id]);
        $draft = $dq->fetch(PDO::FETCH_ASSOC);
        if (!$draft) {
            http_response_code(404);
            echo json_encode(["error" => "Draft not found"]);
            break;
        }
        $check = classifyDraft($draft['content'], $draft['type']);
        if ($check['severity'] === 'block') {
            http_response_code(400);
            echo json_encode(["error" => "Content blocked: " . implode('; ', $check['reasons']), "reasons" => $check['reasons']]);
            break;
        }
        $pdo->prepare("UPDATE ai_drafts SET status='approved' WHERE id=?")->execute([$id]);
        $pdo->prepare("INSERT IGNORE INTO publish_queue (draft_id, status) VALUES (?, 'pending')")->execute([$id]);
        echo json_encode(["status" => "approved"]);
        break;

    case "reject":
        $pdo->prepare("UPDATE ai_drafts SET status='rejected' WHERE id=?")->execute([$id]);
        echo json_encode(["status" => "rejected"]);
        break;

    case "save":
        $pdo->prepare("UPDATE ai_drafts SET content=? WHERE id=?")->execute([$content, $id]);
        echo json_encode(["status" => "saved"]);
        break;

    default:
        http_response_code(400);
        echo json_encode(["error" => "Invalid action"]);
}
