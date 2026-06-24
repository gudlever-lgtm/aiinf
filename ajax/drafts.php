<?php
require_once __DIR__ . "/db.php";

$type   = $_GET['type']   ?? null;
$status = $_GET['status'] ?? null;
$sql    = "SELECT * FROM ai_drafts";
$params = [];
$wheres = [];

if ($type) {
    $wheres[] = "type = ?";
    $params[] = $type;
}

if ($status) {
    $wheres[] = "status = ?";
    $params[] = $status;
}

if ($wheres) {
    $sql .= " WHERE " . implode(" AND ", $wheres);
}

$sql .= " ORDER BY id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$drafts = $stmt->fetchAll();
?>

<h2>Drafts</h2>

<div class="filters">
    <a class="filter-btn <?= !$type ? 'active' : '' ?>" href="#/drafts">All</a>
    <a class="filter-btn <?= $type === 'linkedin_post'   ? 'active' : '' ?>" href="#/drafts?type=linkedin_post">LinkedIn</a>
    <a class="filter-btn <?= $type === 'changelog'       ? 'active' : '' ?>" href="#/drafts?type=changelog">Changelog</a>
    <a class="filter-btn <?= $type === 'founder_update'  ? 'active' : '' ?>" href="#/drafts?type=founder_update">Founder</a>
</div>

<?php if (!$drafts): ?>
<p style="color:#555;margin-top:20px;">No drafts found.</p>
<?php endif; ?>

<?php foreach ($drafts as $d): ?>
<div class="card <?= htmlspecialchars($d['status']) ?>" id="card-<?= $d['id'] ?>">

    <div class="meta">
        ID: <?= $d['id'] ?> &nbsp;|&nbsp;
        Type: <?= htmlspecialchars($d['type'] ?? 'unknown') ?> &nbsp;|&nbsp;
        Status: <strong><?= htmlspecialchars($d['status']) ?></strong>
    </div>

    <textarea id="content-<?= $d['id'] ?>"><?= htmlspecialchars($d['content']) ?></textarea>

    <div style="margin-top:10px;">
        <button class="btn-approve" onclick="draftAction(<?= $d['id'] ?>,'approve')">Accept</button>
        <button class="btn-reject"  onclick="draftAction(<?= $d['id'] ?>,'reject')">Reject</button>
        <button class="btn-save"    onclick="draftSave(<?= $d['id'] ?>)">Save Edit</button>
    </div>

</div>
<?php endforeach; ?>
