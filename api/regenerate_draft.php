<?php

require_once __DIR__ . "/../scripts/env.php";
loadEnv(__DIR__ . "/../.env");
require_once __DIR__ . "/../scripts/ai_helpers.php";

$pdo = new PDO(
    "mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['DB_NAME'] . ";charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

header('Content-Type: application/json');

$id = (int) ($_POST['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    echo json_encode(["error" => "Missing id"]);
    exit;
}

$stmt  = $pdo->prepare("SELECT * FROM ai_drafts WHERE id = ?");
$stmt->execute([$id]);
$draft = $stmt->fetch();

if (!$draft) {
    http_response_code(404);
    echo json_encode(["error" => "Draft not found"]);
    exit;
}

// Fetch events — batch or single
if (!empty($draft['batch_event_ids'])) {
    $eventIds     = json_decode($draft['batch_event_ids'], true) ?: [];
    $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
    $stmt         = $pdo->prepare("SELECT * FROM repo_events WHERE id IN ({$placeholders}) ORDER BY created_at ASC");
    $stmt->execute($eventIds);
    $events = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("SELECT * FROM repo_events WHERE id = ?");
    $stmt->execute([$draft['event_id']]);
    $events = $stmt->fetchAll();
}

if (!$events) {
    http_response_code(422);
    echo json_encode(["error" => "Source event(s) not found"]);
    exit;
}

$n           = count($events);
$commitLines = array_map(fn($e) => "- {$e['message']} ({$e['author']})", $events);
$commitCtx   = implode("\n", $commitLines);

$type = $draft['type'];

if ($type === 'changelog') {
    $e       = $events[0];
    $userMsg = implode("\n", [
        "COMMIT:",
        "- Besked: {$e['message']}",
        "- Forfatter: {$e['author']}",
        "",
        "Skriv én ny changelog-linje på dansk (1-2 sætninger, faktuel). Ingen markdown-formatering. Returner kun teksten.",
    ]);
} elseif ($type === 'linkedin_post') {
    $userMsg = implode("\n", [
        "{$n} commit(s):",
        $commitCtx,
        "",
        "Skriv et nyt LinkedIn-opslag på dansk (2-4 sætninger, ingen preamble). Ingen markdown-formatering. Returner kun teksten.",
    ]);
} elseif ($type === 'founder_update') {
    $userMsg = implode("\n", [
        "{$n} commit(s):",
        $commitCtx,
        "",
        "Skriv en ny founder update på dansk (3-6 sætninger, kun med reel pointe). Ingen markdown-formatering. Returner kun teksten.",
    ]);
} else {
    http_response_code(400);
    echo json_encode(["error" => "Unknown draft type"]);
    exit;
}

$systemMsg = buildSystemMsg($pdo);
// Higher temperature for regeneration so the output is meaningfully different
$content = callMistral($systemMsg, $userMsg, 0.95);

if ($content === null) {
    http_response_code(502);
    echo json_encode(["error" => "Mistral call failed"]);
    exit;
}

$content = stripMarkers($content);

echo json_encode(["content" => $content]);
