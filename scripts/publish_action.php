<?php
require_once __DIR__ . "/env.php";
loadEnv(__DIR__ . "/../.env");

$pdo = new PDO(
    "mysql:host=".$_ENV['DB_HOST'].";dbname=".$_ENV['DB_NAME'].";charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS']
);

$id = $_POST['id'];
$action = $_POST['action'];

if ($action === "reject") {

    $pdo->prepare("
        UPDATE publish_queue SET status='rejected' WHERE id=?
    ")->execute([$id]);

    echo json_encode(["ok"=>true]);
    exit;
}

if ($action === "publish") {

    $targets = json_decode($_POST['targets'], true);

    $pdo->prepare("
        UPDATE publish_queue SET status='approved' WHERE id=?
    ")->execute([$id]);

    $q = $pdo->prepare("
        SELECT * FROM publish_queue WHERE id=?
    ");
    $q->execute([$id]);
    $row = $q->fetch();

    foreach ($targets as $t) {

        $pdo->prepare("
            INSERT INTO publish_targets (queue_id, target)
            VALUES (?,?)
        ")->execute([$id, $t]);
    }

    echo json_encode(["ok"=>true]);
}
