<?php
if (!isset($_GET['run'])) {
    ?>
    <h2>Run DB Migration</h2>
    <p style="color:#888;margin-bottom:20px;">
        Adds <code>processed</code> and <code>retry_count</code> columns to <code>repo_events</code>,
        and a UNIQUE KEY on <code>commit_hash</code>. Safe to run multiple times.
    </p>
    <button class="run-btn" onclick="runScript('migrate', this)">Run Migration</button>
    <div id="script-output"></div>
    <?php
    return;
}

$scriptPath = realpath(__DIR__ . "/../scripts/migrate_001.php");
$output     = shell_exec("php " . escapeshellarg($scriptPath) . " 2>&1");

echo '<div class="output-box"><pre>' . htmlspecialchars($output ?: 'No output') . '</pre></div>';
