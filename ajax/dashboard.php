<?php
require_once __DIR__ . "/db.php";

$totalEvents = $pdo->query("SELECT COUNT(*) FROM repo_events")->fetchColumn();
$totalDrafts = $pdo->query("SELECT COUNT(*) FROM ai_drafts")->fetchColumn();
$approved    = $pdo->query("SELECT COUNT(*) FROM ai_drafts WHERE status='approved'")->fetchColumn();
$published   = $pdo->query("SELECT COUNT(*) FROM ai_drafts WHERE status='published'")->fetchColumn();
$pending     = $pdo->query("SELECT COUNT(*) FROM ai_drafts WHERE status='draft'")->fetchColumn();
?>

<h2>Dashboard</h2>

<div class="stats-grid">
    <div class="stat-card">
        <h2><?= $totalEvents ?></h2>
        <p>Repo Events</p>
    </div>
    <div class="stat-card">
        <h2><?= $totalDrafts ?></h2>
        <p>Total Drafts</p>
    </div>
    <div class="stat-card">
        <h2><?= $pending ?></h2>
        <p>Pending</p>
    </div>
    <div class="stat-card">
        <h2><?= $approved ?></h2>
        <p>Approved</p>
    </div>
    <div class="stat-card">
        <h2><?= $published ?></h2>
        <p>Published</p>
    </div>
</div>

<p style="color:#555;font-size:13px;margin-top:10px;">Oversigt over Fellis AI Content Pipeline</p>
