<?php

require_once __DIR__ . "/scripts/env.php";
loadEnv(__DIR__ . "/.env");

// -----------------------------
// DB
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
// STATS
// -----------------------------
$totalEvents = $pdo->query("SELECT COUNT(*) FROM repo_events")->fetchColumn();
$totalDrafts = $pdo->query("SELECT COUNT(*) FROM ai_drafts")->fetchColumn();
$approved = $pdo->query("SELECT COUNT(*) FROM ai_drafts WHERE status='approved'")->fetchColumn();
$published = $pdo->query("SELECT COUNT(*) FROM ai_drafts WHERE status='published'")->fetchColumn();
$pending = $pdo->query("SELECT COUNT(*) FROM ai_drafts WHERE status='draft'")->fetchColumn();

?>

<!DOCTYPE html>
<html>
<head>
    <title>AIINF Control Center</title>
    <style>
        body {
            margin:0;
            font-family: Arial;
            background:#0f0f0f;
            color:#eaeaea;
        }

        .header {
            padding:30px;
            background:#1a1a1a;
            text-align:center;
        }

        .grid {
            display:grid;
            grid-template-columns: repeat(5, 1fr);
            gap:15px;
            padding:20px;
        }

        .card {
            background:#1c1c1c;
            padding:20px;
            border-radius:10px;
            text-align:center;
        }

        .card h2 {
            margin:0;
            font-size:28px;
        }

        .menu {
            display:flex;
            justify-content:center;
            gap:15px;
            padding:20px;
        }

        a {
            color:#4da3ff;
            text-decoration:none;
        }

        .btn {
            display:inline-block;
            padding:10px 15px;
            background:#2a2a2a;
            border-radius:6px;
        }

        .btn:hover {
            background:#3a3a3a;
        }

    </style>
</head>
<body>

<div class="header">
    <h1>👋 AIINF Control Center</h1>
    <p>Oversigt over Fellis AI Content Pipeline</p>
</div>

<div class="grid">
    <div class="card">
        <h2><?= $totalEvents ?></h2>
        <p>Repo Events</p>
    </div>

    <div class="card">
        <h2><?= $totalDrafts ?></h2>
        <p>Total Drafts</p>
    </div>

    <div class="card">
        <h2><?= $pending ?></h2>
        <p>Pending</p>
    </div>

    <div class="card">
        <h2><?= $approved ?></h2>
        <p>Approved</p>
    </div>

    <div class="card">
        <h2><?= $published ?></h2>
        <p>Published</p>
    </div>
</div>

<div class="menu">

    <a class="btn" href="/drafts.php">📄 Drafts</a>

    <a class="btn" href="/publish_queue.php">🚀 Publish Queue</a>

    <a class="btn" href="/settings.php">⚙️ API Settings</a>

    <a class="btn" href="/scripts/import_commits.php">🔄 Import</a>

    <a class="btn" href="/scripts/generate_drafts.php">🧠 Generate AI</a>

</div>

</body>
</html>
