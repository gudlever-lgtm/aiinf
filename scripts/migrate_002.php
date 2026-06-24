<?php

require_once __DIR__ . "/env.php";
loadEnv(__DIR__ . "/../.env");

$pdo = new PDO(
    "mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['DB_NAME'] . ";charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function columnExists($pdo, $table, $column) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (bool) $stmt->fetchColumn();
}

$steps = [];

// --- ai_drafts: batch_event_ids ---
// Stores a JSON array of all repo_events IDs that contributed to a batch-generated draft.
// NULL for single-commit drafts. Used by the regenerate endpoint to reconstruct batch context.
if (!columnExists($pdo, 'ai_drafts', 'batch_event_ids')) {
    $pdo->exec("ALTER TABLE ai_drafts ADD COLUMN batch_event_ids TEXT NULL");
    $steps[] = "Added ai_drafts.batch_event_ids";
} else {
    $steps[] = "ai_drafts.batch_event_ids already exists — skipped";
}

foreach ($steps as $s) {
    echo $s . "\n";
}
echo "Migration complete.\n";
