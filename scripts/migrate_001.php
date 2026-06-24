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

function indexExists($pdo, $table, $keyName) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
    ");
    $stmt->execute([$table, $keyName]);
    return (bool) $stmt->fetchColumn();
}

$steps = [];

// --- repo_events: processed ---
if (!columnExists($pdo, 'repo_events', 'processed')) {
    $pdo->exec("ALTER TABLE repo_events ADD COLUMN processed TINYINT NOT NULL DEFAULT 0");
    $steps[] = "Added repo_events.processed";
} else {
    $steps[] = "repo_events.processed already exists — skipped";
}

// --- repo_events: retry_count ---
if (!columnExists($pdo, 'repo_events', 'retry_count')) {
    $pdo->exec("ALTER TABLE repo_events ADD COLUMN retry_count TINYINT NOT NULL DEFAULT 0");
    $steps[] = "Added repo_events.retry_count";
} else {
    $steps[] = "repo_events.retry_count already exists — skipped";
}

// --- repo_events: UNIQUE on commit_hash ---
// INSERT IGNORE relies on this being unique; confirm it exists at the DB level.
if (!indexExists($pdo, 'repo_events', 'uq_commit_hash')) {
    // Check for duplicate commit_hash values before adding constraint
    $stmt = $pdo->query("
        SELECT commit_hash, COUNT(*) as cnt
        FROM repo_events
        GROUP BY commit_hash
        HAVING cnt > 1
    ");
    $dupes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($dupes) {
        $steps[] = "WARNING: duplicate commit_hash rows found — cannot add UNIQUE. Clean up first:";
        foreach ($dupes as $d) {
            $steps[] = "  {$d['commit_hash']} ({$d['cnt']} rows)";
        }
    } else {
        $pdo->exec("ALTER TABLE repo_events ADD UNIQUE KEY uq_commit_hash (commit_hash)");
        $steps[] = "Added UNIQUE KEY uq_commit_hash on repo_events.commit_hash";
    }
} else {
    $steps[] = "uq_commit_hash already exists — skipped";
}

foreach ($steps as $s) {
    echo $s . "\n";
}
echo "Migration complete.\n";
