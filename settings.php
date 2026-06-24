<?php
require_once __DIR__ . "/scripts/env.php";
loadEnv(__DIR__ . "/.env");

$pdo = new PDO(
    "mysql:host=".$_ENV['DB_HOST'].";dbname=".$_ENV['DB_NAME'].";charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS']
);

if ($_POST) {

    $service = $_POST['service'];

    $stmt = $pdo->prepare("
        INSERT INTO api_settings
        (service, api_key, api_secret, access_token, refresh_token, base_url)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        api_key=VALUES(api_key),
        api_secret=VALUES(api_secret),
        access_token=VALUES(access_token),
        refresh_token=VALUES(refresh_token),
        base_url=VALUES(base_url)
    ");

    $stmt->execute([
        $service,
        $_POST['api_key'],
        $_POST['api_secret'],
        $_POST['access_token'],
        $_POST['refresh_token'],
        $_POST['base_url']
    ]);

    echo "Saved";
}

$rows = $pdo->query("SELECT * FROM api_settings")->fetchAll(PDO::FETCH_ASSOC);
?>

<h2>API Settings</h2>

<form method="POST">
    <h3>LinkedIn</h3>
    <input name="service" value="linkedin" type="hidden">

    <input name="api_key" placeholder="API Key"><br>
    <input name="api_secret" placeholder="API Secret"><br>
    <input name="access_token" placeholder="Access Token"><br>
    <input name="refresh_token" placeholder="Refresh Token"><br>
    <input name="base_url" placeholder="https://api.linkedin.com"><br>

    <button>Save</button>
</form>

<hr>

<pre>
<?php print_r($rows); ?>
</pre>
