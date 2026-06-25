<?php
require_once __DIR__ . '/../scripts/auth.php';
requireAuth();
if (!isset($_GET['run'])) {
    ?>
    <h2>Import Commits</h2>
    <p style="color:#888;margin-bottom:20px;">
        Import the latest 50 commits from the Fellis repository into the database.
    </p>
    <button class="run-btn" onclick="runScript('import', this)">Run Import</button>
    <div id="script-output"></div>
    <?php
    return;
}

$scriptPath = realpath(__DIR__ . "/../scripts/import_commits.php");
$output     = shell_exec("php " . escapeshellarg($scriptPath) . " 2>&1");

echo '<div class="output-box"><pre>' . htmlspecialchars($output ?: 'No output') . '</pre></div>';
