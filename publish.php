<?php

require_once __DIR__ . "/scripts/env.php";
loadEnv(__DIR__ . "/.env");

// -----------------------------
// DB CONNECTION
// -----------------------------
$pdo = new PDO(
    "mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['DB_NAME'] . ";charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
);

// -----------------------------
// FETCH APPROVED DRAFTS
// -----------------------------
$stmt = $pdo->query("
    SELECT * 
    FROM ai_drafts 
    WHERE status = 'approved'
    ORDER BY created_at ASC
");

$drafts = $stmt->fetchAll();

if (!$drafts) {
    exit("No approved drafts to publish\n");
}

// -----------------------------
// OPTIONAL: simple logger
// -----------------------------
function logPublish($message)
{
    file_put_contents(
        __DIR__ . "/../storage/publish.log",
        date("Y-m-d H:i:s") . " - " . $message . PHP_EOL,
        FILE_APPEND
    );
}

// -----------------------------
// PROCESS PUBLISHING
// -----------------------------
foreach ($drafts as $d) {

    // -----------------------------
    // HERE YOU WOULD INTEGRATE:
    // LinkedIn API / Twitter / CMS / webhook
    // -----------------------------

    $content = $d['content'];
    $type = $d['type'];

    // SIMULATED PUBLISH (MVP)
    logPublish("Publishing draft ID {$d['id']} type {$type}");

    // mark as published
    $stmt = $pdo->prepare("
        UPDATE ai_drafts 
        SET status = 'published' 
        WHERE id = ?
    ");

    $stmt->execute([$d['id']]);

    logPublish("Published draft ID {$d['id']}");
}

echo "Publishing completed\n";

