<?php
require_once __DIR__ . '/../scripts/auth.php';
requireAuth();
require_once __DIR__ . "/db.php";

$items = $pdo->query("
    SELECT q.*, d.content, d.type
    FROM publish_queue q
    JOIN ai_drafts d ON d.id = q.draft_id
    WHERE q.status = 'pending'
    ORDER BY q.id DESC
")->fetchAll();
?>

<h2>Publish Queue</h2>

<?php if (!$items): ?>
<p style="color:#555;margin-top:20px;">No items in the publish queue.</p>
<?php endif; ?>

<?php foreach ($items as $i): ?>
<div class="queue-card" id="q-<?= $i['id'] ?>">

    <h3><?= htmlspecialchars($i['type']) ?></h3>

    <p><?= nl2br(htmlspecialchars($i['content'])) ?></p>

    <div class="target-checkboxes">
        <label><input type="checkbox" class="tgt-<?= $i['id'] ?>" value="linkedin"> LinkedIn</label>
        <label><input type="checkbox" class="tgt-<?= $i['id'] ?>" value="blog"> Blog</label>
        <label><input type="checkbox" class="tgt-<?= $i['id'] ?>" value="changelog"> Changelog</label>
    </div>

    <div style="margin-top:12px;">
        <button class="btn-publish" onclick="publishApprove(<?= $i['id'] ?>)">Approve Publish</button>
        <button class="btn-reject"  onclick="publishReject(<?= $i['id'] ?>)">Reject</button>
    </div>

</div>
<?php endforeach; ?>
