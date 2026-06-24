<?php
// One-off migration: encrypt any plaintext secrets in api_settings.
// Idempotent — already-encrypted rows (prefixed "enc:") are skipped.
// Run once after deploying the encryption changes:
//   php scripts/migrate_encrypt_credentials.php

require_once __DIR__ . "/env.php";
loadEnv(__DIR__ . "/../.env");
require_once __DIR__ . "/crypto.php";

$pdo = new PDO(
    "mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['DB_NAME'] . ";charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$secretFields = ['api_key', 'api_secret', 'access_token', 'refresh_token'];
$rows = $pdo->query("SELECT * FROM api_settings")->fetchAll(PDO::FETCH_ASSOC);

$encrypted = 0;
$alreadyEncrypted = 0;
$skipped = 0;

foreach ($rows as $row) {
    $updates = [];
    $needsWrite = false;

    foreach ($secretFields as $field) {
        $val = $row[$field] ?? '';

        if ($val === '') {
            $skipped++;
            $updates[$field] = $val;
            continue;
        }

        if (strncmp($val, 'enc:', 4) === 0) {
            $alreadyEncrypted++;
            $updates[$field] = $val;
            continue;
        }

        $updates[$field] = encrypt($val);
        $needsWrite = true;
        $encrypted++;
    }

    if ($needsWrite) {
        $pdo->prepare("
            UPDATE api_settings
            SET api_key=?, api_secret=?, access_token=?, refresh_token=?
            WHERE service=?
        ")->execute([
            $updates['api_key'],
            $updates['api_secret'],
            $updates['access_token'],
            $updates['refresh_token'],
            $row['service'],
        ]);
        echo "Migrated: service={$row['service']}\n";
    }
}

echo "\nDone.\n";
echo "  Fields encrypted now : $encrypted\n";
echo "  Already encrypted    : $alreadyEncrypted\n";
echo "  Empty / skipped      : $skipped\n";
