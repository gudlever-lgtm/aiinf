<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . "/env.php";
loadEnv(__DIR__ . "/../.env");

// -----------------------------
// DB CONNECT
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
// REPO PATH
// -----------------------------
$repoPath = "/var/www/aiinf.gnf.dk/repos/fellis";

// -----------------------------
// GET GIT COMMITS
// -----------------------------
$cmd = "git -C $repoPath log --pretty=format:\"%H|%an|%s\" -n 50";

$output = shell_exec($cmd . " 2>&1");

if (!$output || trim($output) === "") {
    die("No commits found or git error. Output:\n" . $output);
}

// -----------------------------
// PARSE OUTPUT
// -----------------------------
$lines = explode("\n", trim($output));

$count = 0;

foreach ($lines as $line) {

    if (strpos($line, "|") === false) {
        continue;
    }

    [$hash, $author, $message] = explode("|", $line, 3);

    if (!$hash) continue;

    // -----------------------------
    // INSERT INTO DB
    // -----------------------------
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO repo_events
        (commit_hash, author, message, created_at)
        VALUES (?, ?, ?, NOW())
    ");

    $stmt->execute([$hash, $author, $message]);

    $count++;
}

// -----------------------------
// RESULT
// -----------------------------
echo "Import completed. Inserted/processed commits: " . $count . "\n";
