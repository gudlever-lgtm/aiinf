<?php
require_once __DIR__ . "/db.php";

$rows = $pdo->query("SELECT * FROM api_settings")->fetchAll();
?>

<h2>API Settings</h2>

<div class="settings-form">
    <h3>LinkedIn</h3>
    <form id="settings-form" onsubmit="event.preventDefault(); saveSettings(this)">
        <input name="service" value="linkedin" type="hidden">
        <input name="api_key"       placeholder="API Key">
        <input name="api_secret"    placeholder="API Secret">
        <input name="access_token"  placeholder="Access Token">
        <input name="refresh_token" placeholder="Refresh Token">
        <input name="base_url"      placeholder="https://api.linkedin.com">
        <button type="submit" class="btn-save" style="margin-top:10px;">Save</button>
    </form>
</div>

<hr>

<h3>Stored Settings</h3>
<pre><?= htmlspecialchars(print_r($rows, true)) ?></pre>
