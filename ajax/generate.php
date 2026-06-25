<?php
require_once __DIR__ . '/../scripts/auth.php';
requireAuth();
if (!isset($_GET['run'])) {
    ?>
    <h2>Generate AI Drafts</h2>
    <p style="color:#888;margin-bottom:20px;">
        Generate Changelog, LinkedIn post, and Founder update drafts for each unprocessed commit via Mistral AI.
    </p>
    <button class="run-btn" onclick="runScript('generate', this)">Run Generator</button>
    <div id="script-output"></div>
    <?php
    return;
}

$scriptPath = realpath(__DIR__ . "/../scripts/generate_drafts.php");
$output     = shell_exec("php " . escapeshellarg($scriptPath) . " 2>&1");

echo '<div class="output-box"><pre>' . htmlspecialchars($output ?: 'No output') . '</pre></div>';
