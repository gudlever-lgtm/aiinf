<?php
require_once __DIR__ . "/../scripts/env.php";
loadEnv(__DIR__ . "/../.env");
require_once __DIR__ . "/../scripts/crypto.php";

$pdo = new PDO(
    "mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['DB_NAME'] . ";charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
    exit;
}

$service = $_POST['service'] ?? null;

if (!$service) {
    http_response_code(400);
    echo json_encode(["error" => "Missing service"]);
    exit;
}

// Fetch existing row so blank-submitted fields keep their stored value.
$stmt = $pdo->prepare("SELECT * FROM api_settings WHERE service = ?");
$stmt->execute([$service]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$secretFields = ['api_key', 'api_secret', 'access_token', 'refresh_token'];
$toStore = [];

foreach ($secretFields as $field) {
    $posted = $_POST[$field] ?? '';
    $toStore[$field] = ($posted !== '')
        ? encrypt($posted)
        : ($existing[$field] ?? '');
}

$pdo->prepare("
    INSERT INTO api_settings (service, api_key, api_secret, access_token, refresh_token, base_url)
    VALUES (?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        api_key       = VALUES(api_key),
        api_secret    = VALUES(api_secret),
        access_token  = VALUES(access_token),
        refresh_token = VALUES(refresh_token),
        base_url      = VALUES(base_url)
")->execute([
    $service,
    $toStore['api_key'],
    $toStore['api_secret'],
    $toStore['access_token'],
    $toStore['refresh_token'],
    $_POST['base_url'] ?? ($existing['base_url'] ?? ''),
]);

echo json_encode(["ok" => true]);
