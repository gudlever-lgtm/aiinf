<?php

require_once __DIR__ . "/env.php";
loadEnv(__DIR__ . "/../.env");

$pdo = new PDO(
    "mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['DB_NAME'] . ";charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function cols(PDO $pdo, string $table): array {
    return array_column($pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(), 'Field');
}

echo "Running migrations...\n\n";

// -----------------------------------------------------------------------
// repo_events
// -----------------------------------------------------------------------

$pdo->exec("ALTER TABLE repo_events ADD COLUMN IF NOT EXISTS repo VARCHAR(255) NULL AFTER id");
echo "repo_events.repo column: ok\n";

$pdo->exec("UPDATE repo_events SET repo = 'fellis' WHERE repo IS NULL");
echo "repo_events.repo backfill: ok\n";

// Remove duplicate commit_hash rows before adding the unique index
$pdo->exec("
    DELETE r1 FROM repo_events r1
    INNER JOIN repo_events r2 ON r1.commit_hash = r2.commit_hash AND r1.id > r2.id
");
echo "repo_events dedup: ok\n";

$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_commit_hash ON repo_events (commit_hash)");
echo "repo_events UNIQUE(commit_hash): ok\n";

// -----------------------------------------------------------------------
// ai_drafts
// -----------------------------------------------------------------------

$pdo->exec("ALTER TABLE ai_drafts ADD COLUMN IF NOT EXISTS published_id VARCHAR(100) NULL AFTER published_at");
$pdo->exec("ALTER TABLE ai_drafts ADD COLUMN IF NOT EXISTS error TEXT NULL AFTER published_id");
echo "ai_drafts.published_id + error columns: ok\n";

if (in_array('publish_status', cols($pdo, 'ai_drafts'))) {
    $pdo->exec("ALTER TABLE ai_drafts DROP COLUMN publish_status");
    echo "ai_drafts.publish_status dropped: ok\n";
} else {
    echo "ai_drafts.publish_status: already absent\n";
}

// Dedup on (event_id, type) — keep the lowest id per pair
$pdo->exec("
    DELETE d1 FROM ai_drafts d1
    INNER JOIN ai_drafts d2
        ON d1.event_id = d2.event_id AND d1.type = d2.type AND d1.id > d2.id
");
echo "ai_drafts dedup: ok\n";

$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_event_type ON ai_drafts (event_id, type)");
echo "ai_drafts UNIQUE(event_id, type): ok\n";

// -----------------------------------------------------------------------
// publish_queue
// -----------------------------------------------------------------------

// Dedup on draft_id — keep the lowest id
$pdo->exec("
    DELETE q1 FROM publish_queue q1
    INNER JOIN publish_queue q2 ON q1.draft_id = q2.draft_id AND q1.id > q2.id
");
echo "publish_queue dedup: ok\n";

$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_pq_draft_id ON publish_queue (draft_id)");
echo "publish_queue UNIQUE(draft_id): ok\n";

// -----------------------------------------------------------------------
// publish_targets — ensure queue_id column (rename from draft_id if needed)
// -----------------------------------------------------------------------

$ptCols = cols($pdo, 'publish_targets');
if (in_array('draft_id', $ptCols) && !in_array('queue_id', $ptCols)) {
    $pdo->exec("ALTER TABLE publish_targets CHANGE draft_id queue_id INT NULL");
    echo "publish_targets: renamed draft_id -> queue_id\n";
} elseif (!in_array('queue_id', $ptCols)) {
    $pdo->exec("ALTER TABLE publish_targets ADD COLUMN IF NOT EXISTS queue_id INT NULL AFTER id");
    echo "publish_targets.queue_id: added\n";
} else {
    echo "publish_targets.queue_id: already exists\n";
}

// -----------------------------------------------------------------------
// api_settings
// -----------------------------------------------------------------------

$pdo->exec("ALTER TABLE api_settings ADD COLUMN IF NOT EXISTS author_urn VARCHAR(100) NULL AFTER base_url");
echo "api_settings.author_urn: ok\n";

$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_service ON api_settings (service)");
echo "api_settings UNIQUE(service): ok\n";

echo "\nAll migrations complete.\n";
echo "IMPORTANT: add ENCRYPTION_KEY=<random-string> to your .env to enable credential encryption.\n";
