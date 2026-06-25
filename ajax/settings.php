<?php
require_once __DIR__ . '/../scripts/auth.php';
requireAuth();
require_once __DIR__ . "/db.php";
require_once __DIR__ . "/../scripts/crypto.php";

$rows = $pdo->query("SELECT * FROM api_settings")->fetchAll();
$li = null;
foreach ($rows as $r) {
    if ($r['service'] === 'linkedin') { $li = $r; break; }
}

function maskSecret(?string $stored): string {
    if ($stored === null || $stored === '') return '(not set)';
    if (strncmp($stored, 'enc:', 4) === 0) {
        $plain = decrypt($stored);
        if ($plain === false) return '(decrypt error)';
        $s = $plain;
    } else {
        $s = $stored; // pre-migration plaintext — mask it too
    }
    $len = strlen($s);
    return ($len > 4 ? str_repeat('*', $len - 4) : '') . substr($s, -4);
}
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
                <li><strong style="color:#ccc;">Author URN</strong> — Your LinkedIn member URN, e.g. <code>urn:li:person:ABC123</code> (find it via <code>/v2/me</code>)</li>
                <li><strong style="color:#ccc;">Base URL</strong> — Always <code>https://api.linkedin.com</code></li>
            </ul>

            <p style="margin-top:12px;margin-bottom:10px;color:#aaa;font-weight:600;">3. Get an access token</p>
            <p>LinkedIn uses OAuth 2.0. Redirect users to the authorization URL with scopes <code>w_member_social</code> (posting) and <code>r_liteprofile</code>, then exchange the returned code at <code>/v2/accessToken</code> to receive your access token and refresh token.</p>

            <p style="margin-top:12px;color:#555;">Tokens expire after 60 days. Use the refresh token to obtain a new one without re-authorizing.</p>
        </div>
    </details>

    <?php if ($li): ?>
    <p style="font-size:12px;color:#555;margin-bottom:14px;">Leave a field blank to keep the existing value.</p>
    <?php endif; ?>

    <form id="settings-form" onsubmit="event.preventDefault(); saveSettings(this)">
        <input name="service" value="linkedin" type="hidden">

        <div style="margin-bottom:8px;">
            <input name="api_key" placeholder="<?= $li ? 'Replace API Key…' : 'API Key' ?>">
            <?php if ($li): ?><small style="font-size:11px;color:#555;margin-left:6px;"><?= htmlspecialchars(maskSecret($li['api_key'] ?? '')) ?></small><?php endif; ?>
        </div>

        <div style="margin-bottom:8px;">
            <input name="api_secret" placeholder="<?= $li ? 'Replace API Secret…' : 'API Secret' ?>">
            <?php if ($li): ?><small style="font-size:11px;color:#555;margin-left:6px;"><?= htmlspecialchars(maskSecret($li['api_secret'] ?? '')) ?></small><?php endif; ?>
        </div>

        <div style="margin-bottom:8px;">
            <input name="access_token" placeholder="<?= $li ? 'Replace Access Token…' : 'Access Token' ?>">
            <?php if ($li): ?><small style="font-size:11px;color:#555;margin-left:6px;"><?= htmlspecialchars(maskSecret($li['access_token'] ?? '')) ?></small><?php endif; ?>
        </div>

        <div style="margin-bottom:8px;">
            <input name="refresh_token" placeholder="<?= $li ? 'Replace Refresh Token…' : 'Refresh Token' ?>">
            <?php if ($li): ?><small style="font-size:11px;color:#555;margin-left:6px;"><?= htmlspecialchars(maskSecret($li['refresh_token'] ?? '')) ?></small><?php endif; ?>
        </div>

        <input name="author_urn" type="text" placeholder="Author URN (e.g. urn:li:person:ABC123)" value="<?= htmlspecialchars($li['author_urn'] ?? '') ?>" style="margin-bottom:8px;">
        <input name="base_url" placeholder="https://api.linkedin.com" value="<?= htmlspecialchars($li['base_url'] ?? '') ?>">

        <button type="submit" class="btn-save" style="margin-top:10px;">Save</button>
    </form>
</div>

<hr>

<h3>Current Settings</h3>
<?php if (!$rows): ?>
<p style="color:#555;font-size:13px;">No settings saved yet.</p>
<?php elseif (!$li): ?>
<p style="color:#555;font-size:13px;">No LinkedIn settings saved yet.</p>
<?php else: ?>
<table style="font-size:12px;color:#888;border-collapse:collapse;width:100%;margin-top:10px;">
    <thead>
        <tr style="color:#aaa;text-align:left;border-bottom:1px solid #222;">
            <th style="padding:6px 12px;">Field</th>
            <th style="padding:6px 12px;">Value</th>
        </tr>
    </thead>
    <tbody>
        <tr><td style="padding:5px 12px;color:#666;">Service</td><td><?= htmlspecialchars($li['service']) ?></td></tr>
        <tr><td style="padding:5px 12px;color:#666;">API Key</td><td><?= htmlspecialchars(maskSecret($li['api_key'] ?? '')) ?></td></tr>
        <tr><td style="padding:5px 12px;color:#666;">API Secret</td><td><?= htmlspecialchars(maskSecret($li['api_secret'] ?? '')) ?></td></tr>
        <tr><td style="padding:5px 12px;color:#666;">Access Token</td><td><?= htmlspecialchars(maskSecret($li['access_token'] ?? '')) ?></td></tr>
        <tr><td style="padding:5px 12px;color:#666;">Refresh Token</td><td><?= htmlspecialchars(maskSecret($li['refresh_token'] ?? '')) ?></td></tr>
        <tr><td style="padding:5px 12px;color:#666;">Author URN</td><td><?= $li['author_urn'] ? htmlspecialchars($li['author_urn']) : '<em style="color:#444;">(not set)</em>' ?></td></tr>
        <tr><td style="padding:5px 12px;color:#666;">Base URL</td><td><?= $li['base_url'] ? htmlspecialchars($li['base_url']) : '<em style="color:#444;">(not set)</em>' ?></td></tr>
    </tbody>
</table>
<?php endif; ?>
