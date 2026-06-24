<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . "/env.php";
loadEnv(__DIR__ . "/../.env");

$pdo = new PDO(
    "mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['DB_NAME'] . ";charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
);

// -----------------------------
// REPO CONFIG
// -----------------------------
$repoPath = "/var/www/aiinf.gnf.dk/repos/fellis";
$repoName = "fellis";

// -----------------------------
// GET GIT COMMITS
// -----------------------------
$cmd    = "git -C " . escapeshellarg($repoPath) . " log --pretty=format:\"%H|%an|%s\" -n 50";
$output = shell_exec($cmd . " 2>&1");

if (!$output || trim($output) === "") {
    die("No commits found or git error. Output:\n" . $output);
}

// -----------------------------
// PROCESS COMMITS
// -----------------------------
$lines    = explode("\n", trim($output));
$inserted = 0;
$skipped  = 0;

$stmt = $pdo->prepare("
    INSERT IGNORE INTO repo_events (repo, commit_hash, author, message, changed_files, created_at)
    VALUES (?, ?, ?, ?, ?, NOW())
");

foreach ($lines as $line) {

    if (strpos($line, "|") === false) continue;

    [$hash, $author, $message] = explode("|", $line, 3);

    if (!$hash) continue;

    // Skip merge commits at ingest — they produce no meaningful content
    if (preg_match('/^Merge (pull request|branch|remote(-tracking)? branch)/i', trim($message))) {
        $skipped++;
        continue;
    }

    // Collect changed files for this commit
    $filesOut     = shell_exec(
        "git -C " . escapeshellarg($repoPath)
        . " diff-tree --no-commit-id -r --name-only "
        . escapeshellarg($hash) . " 2>/dev/null"
    );
    $changedFiles = $filesOut ? trim($filesOut) : null;

    $stmt->execute([$repoName, $hash, $author, $message, $changedFiles]);

    if ($stmt->rowCount() > 0) {
        $inserted++;
    }
}

echo "Import completed. Inserted: $inserted, Skipped (merge commits): $skipped\n";
