<?php
require_once __DIR__ . "/db.php";

$rows = $pdo->query("SELECT * FROM api_settings")->fetchAll();
?>

<h2>API Settings</h2>

<div class="settings-form">
    <h3>LinkedIn</h3>

    <details style="margin-bottom:18px;">
        <summary style="cursor:pointer;font-size:12px;color:#4da3ff;user-select:none;">How to connect LinkedIn</summary>
        <div style="margin-top:12px;padding:14px 16px;background:#161616;border:1px solid #252525;border-radius:8px;font-size:12px;line-height:1.7;color:#888;">
            <p style="margin-bottom:10px;color:#aaa;font-weight:600;">1. Create a LinkedIn app</p>
            <p>Go to <a href="https://www.linkedin.com/developers/apps" target="_blank" style="color:#4da3ff;">linkedin.com/developers/apps</a>, create an app, then add the <em>Share on LinkedIn</em> product under the <strong>Products</strong> tab.</p>

            <p style="margin-top:12px;margin-bottom:10px;color:#aaa;font-weight:600;">2. Get your credentials</p>
            <ul style="padding-left:18px;display:flex;flex-direction:column;gap:4px;">
                <li><strong style="color:#ccc;">API Key</strong> — Client ID shown on the Auth tab</li>
                <li><strong style="color:#ccc;">API Secret</strong> — Client Secret shown on the Auth tab</li>
                <li><strong style="color:#ccc;">Access Token</strong> — Generate via OAuth 2.0 (see step 3)</li>
                <li><strong style="color:#ccc;">Refresh Token</strong> — Returned alongside the access token if <code>offline_access</code> scope is granted</li>
                <li><strong style="color:#ccc;">Base URL</strong> — Always <code>https://api.linkedin.com</code></li>
            </ul>

            <p style="margin-top:12px;margin-bottom:10px;color:#aaa;font-weight:600;">3. Get an access token</p>
            <p>LinkedIn uses OAuth 2.0. Redirect users to the authorization URL with scopes <code>w_member_social</code> (posting) and <code>r_liteprofile</code>, then exchange the returned code at <code>/v2/accessToken</code> to receive your access token and refresh token.</p>

            <p style="margin-top:12px;color:#555;">Tokens expire after 60 days. Use the refresh token to obtain a new one without re-authorizing.</p>
        </div>
    </details>

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
