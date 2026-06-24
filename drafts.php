<?php
require_once __DIR__ . "/scripts/env.php";
loadEnv(__DIR__ . "/.env");

$pdo = new PDO(
    "mysql:host=".$_ENV['DB_HOST'].";dbname=".$_ENV['DB_NAME'].";charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS']
);

$type = $_GET['type'] ?? null;

$sql = "SELECT * FROM ai_drafts";
$params = [];

if ($type) {
    $sql .= " WHERE type = ?";
    $params[] = $type;
}

$sql .= " ORDER BY id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$drafts = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>AIINF Drafts</title>

    <style>
        body { font-family: Arial; background:#111; color:#eee; margin:0; padding:20px; }

        .card {
            background:#1c1c1c;
            padding:15px;
            margin-bottom:15px;
            border-radius:8px;
            border-left:5px solid orange;
            transition: all 0.2s ease;
        }

        .meta { font-size:12px; color:#aaa; margin-bottom:10px; }

        textarea {
            width:100%;
            height:140px;
            background:#222;
            color:#fff;
            border:1px solid #333;
            padding:10px;
            border-radius:6px;
        }

        button {
            padding:6px 10px;
            margin-right:5px;
            cursor:pointer;
            border:0;
            border-radius:5px;
            background:#2a2a2a;
            color:#fff;
        }

        button:hover { background:#3a3a3a; }

        .approved { border-left:5px solid green !important; }
        .rejected { border-left:5px solid red !important; }
        .draft { border-left:5px solid orange !important; }

        .filters a {
            color:#0af;
            margin-right:10px;
            text-decoration:none;
        }

        .topbar {
            margin-bottom:20px;
        }
    </style>
</head>

<body>

<h2>AIINF Drafts</h2>

<div class="filters">
    <a href="drafts.php">All</a>
    <a href="drafts.php?type=linkedin_post">LinkedIn</a>
    <a href="drafts.php?type=changelog">Changelog</a>
    <a href="drafts.php?type=founder_update">Founder</a>
</div>

<hr>

<?php foreach ($drafts as $d): ?>

<div class="card <?= htmlspecialchars($d['status']) ?>" id="card-<?= $d['id'] ?>">

    <div class="meta">
        ID: <?= $d['id'] ?> |
        Type: <?= htmlspecialchars($d['type'] ?? 'unknown') ?> |
        Status: <?= $d['status'] ?>
    </div>

    <textarea id="content-<?= $d['id'] ?>"><?= htmlspecialchars($d['content']) ?></textarea>

    <div style="margin-top:10px;">
        <button onclick="action(<?= $d['id'] ?>,'approve')">Accept</button>
        <button onclick="action(<?= $d['id'] ?>,'reject')">Reject</button>
        <button onclick="save(<?= $d['id'] ?>)">Save Edit</button>
    </div>

</div>

<?php endforeach; ?>

<script>

function action(id, type) {

    fetch('/scripts/draft_action.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `id=${id}&action=${type}`
    })
    .then(r => r.json())
    .then(res => {

        const card = document.getElementById('card-' + id);

        if (res.status === 'approved') {
            card.classList.remove('rejected', 'draft');
            card.classList.add('approved');
        }

        if (res.status === 'rejected') {
            card.classList.remove('approved', 'draft');
            card.classList.add('rejected');
        }

    })
    .catch(err => console.error(err));
}

function save(id) {

    const content = document.getElementById('content-' + id).value;

    fetch('/scripts/draft_action.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `id=${id}&action=save&content=` + encodeURIComponent(content)
    })
    .then(r => r.json())
    .then(res => {

        if (res.status === 'saved') {
            const card = document.getElementById('card-' + id);
            card.style.boxShadow = "0 0 10px #4da3ff";
            setTimeout(() => card.style.boxShadow = "none", 800);
        }

    })
    .catch(err => console.error(err));
}

</script>

</body>
</html>
