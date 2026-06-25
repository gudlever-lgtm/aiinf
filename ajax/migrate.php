<?php
require_once __DIR__ . '/../scripts/auth.php';
requireAuth();
if (!isset($_GET['run'])) {
    ?>
    <h2>Run DB Migration</h2>
    <p style="color:#888;margin-bottom:20px;">
        Applies all pending schema changes. Safe to run multiple times.<br>
        001: <code>processed</code>, <code>retry_count</code> on <code>repo_events</code>, UNIQUE KEY on <code>commit_hash</code>.<br>
        002: <code>batch_event_ids</code> on <code>ai_drafts</code> (for batch LinkedIn/Founder drafts).
    </p>
    <button class="run-btn" onclick="runScript('migrate', this)">Run Migration</button>
    <div id="script-output"></div>
    <?php
    return;
}

$out1 = shell_exec("php " . escapeshellarg(realpath(__DIR__ . "/../scripts/migrate_001.php")) . " 2>&1");
$out2 = shell_exec("php " . escapeshellarg(realpath(__DIR__ . "/../scripts/migrate_002.php")) . " 2>&1");

echo '<div class="output-box"><pre>' . htmlspecialchars(($out1 ?: '') . "\n" . ($out2 ?: '')) . '</pre></div>';
