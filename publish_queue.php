<?php
require_once __DIR__ . "/scripts/env.php";
loadEnv(__DIR__ . "/.env");

$pdo = new PDO(
    "mysql:host=".$_ENV['DB_HOST'].";dbname=".$_ENV['DB_NAME'].";charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS']
);

$items = $pdo->query("
    SELECT q.*, d.content, d.type
    FROM publish_queue q
    JOIN ai_drafts d ON d.id = q.draft_id
    WHERE q.status = 'pending'
    ORDER BY q.id DESC
")->fetchAll();
?>

<h2>Publish Queue</h2>

<?php foreach ($items as $i): ?>

<div class="card" id="q-<?= $i['id'] ?>">

    <h3><?= htmlspecialchars($i['type']) ?></h3>
    <p><?= nl2br(htmlspecialchars($i['content'])) ?></p>

    <label>
        <input type="checkbox" class="tgt-<?= $i['id'] ?>" value="linkedin"> LinkedIn
    </label>

    <label>
        <input type="checkbox" class="tgt-<?= $i['id'] ?>" value="blog"> Blog
    </label>

    <label>
        <input type="checkbox" class="tgt-<?= $i['id'] ?>" value="changelog"> Changelog
    </label>

    <br><br>

    <button onclick="publish(<?= $i['id'] ?>)">Approve Publish</button>
    <button onclick="reject(<?= $i['id'] ?>)">Reject</button>

</div>

<?php endforeach; ?>

<script>

function getTargets(id) {
    let boxes = document.querySelectorAll('.tgt-' + id);
    let out = [];
    boxes.forEach(b => {
        if (b.checked) out.push(b.value);
    });
    return out;
}

function publish(id) {

    let targets = getTargets(id);

    fetch('/scripts/publish_action.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `id=${id}&action=publish&targets=` + encodeURIComponent(JSON.stringify(targets))
    })
    .then(r => r.json())
    .then(res => {
        document.getElementById('q-' + id).style.opacity = 0.3;
    });

}

function reject(id) {

    fetch('/scripts/publish_action.php', {
        method: 'POST',
        body: `id=${id}&action=reject`
    });

    document.getElementById('q-' + id).style.display = 'none';
}

</script>
