<?php
// One-off sweep: classifyDraft over every ai_drafts row and report.
// Does NOT modify any data. Run after db_migrate_safety.sql.
// Note: calls the Mistral API once per draft row — may be slow and incur API cost.

require_once __DIR__ . "/env.php";
loadEnv(__DIR__ . "/../.env");

require_once __DIR__ . "/content_safety.php";

$pdo = new PDO(
    "mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['DB_NAME'] . ";charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$drafts = $pdo->query("SELECT id, event_id, type, content, status FROM ai_drafts ORDER BY id ASC")->fetchAll();

$queuedIds = $pdo->query(
    "SELECT draft_id FROM publish_queue WHERE status IN ('pending', 'approved')"
)->fetchAll(PDO::FETCH_COLUMN);
$inQueue = array_flip($queuedIds);

$blockedInQueue = [];
$counts = ['block' => 0, 'flag' => 0, 'ok' => 0];

echo str_pad("ID", 6) . str_pad("Type", 20) . str_pad("DB status", 12) . str_pad("Severity", 10) . "Reasons\n";
echo str_repeat("-", 100) . "\n";

foreach ($drafts as $d) {
    $check    = classifyDraft($d['content'], $d['type']);
    $severity = $check['severity'];
    $counts[$severity]++;

    $queueFlag = isset($inQueue[$d['id']]) ? ' [IN QUEUE]' : '';
    if (isset($inQueue[$d['id']]) && $severity === 'block') {
        $blockedInQueue[] = $d['id'];
        $queueFlag = ' *** IN QUEUE — BLOCKED ***';
    }

    $reasons = implode('; ', $check['reasons']);
    echo str_pad($d['id'], 6)
       . str_pad($d['type'], 20)
       . str_pad($d['status'], 12)
       . str_pad($severity, 10)
       . $reasons
       . $queueFlag
       . "\n";
}

echo str_repeat("=", 100) . "\n";
echo "Total: " . count($drafts) . " | block: {$counts['block']} | flag: {$counts['flag']} | ok: {$counts['ok']}\n";

if ($blockedInQueue) {
    echo "\n!!! ALERT: draft IDs in publish_queue that classify as BLOCK — purge before enabling safety gate:\n";
    echo implode(', ', $blockedInQueue) . "\n";
} else {
    echo "No blocked items found in publish_queue.\n";
}
