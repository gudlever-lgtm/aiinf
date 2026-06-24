<?php

require_once __DIR__ . "/env.php";
loadEnv(__DIR__ . "/../.env");
require_once __DIR__ . "/ai_helpers.php";

define('MAX_RETRIES', 2);

// processed column values:
// 0 = pending, 1 = drafted, 2 = skipped (noise/SKIP), 3 = error (retries exhausted)

$pdo = new PDO(
    "mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['DB_NAME'] . ";charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$stmt = $pdo->query("
    SELECT * FROM repo_events
    WHERE processed = 0 AND retry_count < " . MAX_RETRIES . "
    ORDER BY created_at ASC
");
$events = $stmt->fetchAll();

if (!$events) {
    exit("No new events\n");
}

// -------------------------------------------------------
// Significance classifier
// Returns: 'skip' | 'changelog' | 'notable'
// -------------------------------------------------------
function classifyCommit(string $message): string
{
    // Merge commits
    if (preg_match('/^Merge (pull request|branch)/i', $message)) return 'skip';

    // Conventional-commit noise types
    if (preg_match('/^(chore|ci|build|style|refactor|test)(\(.*?\))?:/i', $message)) return 'skip';

    // Lockfile / dep bumps
    if (preg_match('/^(bump|update)\s+(version|deps|dependencies|lock|lockfile)/i', $message)) return 'skip';

    // Micro-edits
    if (preg_match('/^(fix typo|remove duplicate|whitespace|formatting|revert ")/i', $message)) return 'skip';

    // Notable: shipped feature
    if (preg_match('/^(feat|feature)(\(.*?\))?:/i', $message)) return 'notable';

    // Notable: release / launch / ship
    if (preg_match('/\b(release|v\d+\.\d+|launch|ship)\b/i', $message)) return 'notable';

    // Notable: substantive fix (message longer than 40 chars after prefix)
    if (preg_match('/^fix(\(.*?\))?:/i', $message) && strlen($message) > 40) return 'notable';

    return 'changelog';
}

// -------------------------------------------------------
// DB helpers
// -------------------------------------------------------
function draftExists(PDO $pdo, int $eventId, string $type): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM ai_drafts WHERE event_id = ? AND type = ? LIMIT 1");
    $stmt->execute([$eventId, $type]);
    return (bool) $stmt->fetch();
}

function insertDraft(PDO $pdo, int $eventId, string $type, string $content, ?string $batchEventIds = null): void
{
    if (draftExists($pdo, $eventId, $type)) return;
    $stmt = $pdo->prepare("INSERT INTO ai_drafts (event_id, type, content, status, batch_event_ids) VALUES (?, ?, ?, 'draft', ?)");
    $stmt->execute([$eventId, $type, $content, $batchEventIds]);
}

function markProcessed(PDO $pdo, int $eventId, int $status): void
{
    $pdo->prepare("UPDATE repo_events SET processed = ? WHERE id = ?")->execute([$status, $eventId]);
}

function incrementRetry(PDO $pdo, int $eventId): void
{
    $pdo->prepare("UPDATE repo_events SET retry_count = retry_count + 1 WHERE id = ?")->execute([$eventId]);
    $stmt = $pdo->prepare("SELECT retry_count FROM repo_events WHERE id = ?");
    $stmt->execute([$eventId]);
    $row = $stmt->fetch();
    if ($row && $row['retry_count'] >= MAX_RETRIES) {
        markProcessed($pdo, $eventId, 3);
    }
}

// -------------------------------------------------------
// System message with few-shot examples from approved drafts
// -------------------------------------------------------
$systemMsg = buildSystemMsg($pdo);

// -------------------------------------------------------
// Main loop — generate per-commit changelogs
// Notable commits are collected for a single batch LinkedIn + Founder call
// -------------------------------------------------------
$notableEvents = [];

foreach ($events as $event) {
    $classification = classifyCommit($event['message']);

    if ($classification === 'skip') {
        echo "Skip (noise): {$event['commit_hash']} — {$event['message']}\n";
        markProcessed($pdo, $event['id'], 2);
        continue;
    }

    $userMsg = implode("\n", [
        "COMMIT:",
        "- Besked: {$event['message']}",
        "- Forfatter: {$event['author']}",
        "",
        "Skriv én changelog-linje på dansk (1-2 sætninger, faktuel). Ingen markdown-formatering. Returner kun teksten, eller SKIP.",
    ]);

    $output = callMistral($systemMsg, $userMsg);

    if ($output === null) {
        error_log("Mistral failed for event {$event['id']}");
        incrementRetry($pdo, $event['id']);
        continue;
    }

    $changelog = stripMarkers($output);

    if ($changelog === '' || strtolower($changelog) === 'skip') {
        error_log("Skipped by model for event {$event['id']}: {$event['message']}");
        markProcessed($pdo, $event['id'], 2);
        continue;
    }

    insertDraft($pdo, $event['id'], 'changelog', $changelog);
    markProcessed($pdo, $event['id'], 1);
    echo "Changelog: {$event['commit_hash']}\n";

    if ($classification === 'notable') {
        $notableEvents[] = $event;
    }
}

// -------------------------------------------------------
// Batch LinkedIn + Founder for all notable commits in this run
// One call covers all notable commits, producing a single post of each type
// -------------------------------------------------------
if (!empty($notableEvents)) {
    $anchorEvent   = $notableEvents[count($notableEvents) - 1];
    $batchEventIds = json_encode(array_column($notableEvents, 'id'));
    $n             = count($notableEvents);

    $commitLines = array_map(
        fn($e) => "- {$e['message']} ({$e['author']})",
        $notableEvents
    );

    $batchUserMsg = implode("\n", [
        "{$n} commit(s) der er værd at kommunikere:",
        implode("\n", $commitLines),
        "",
        "Skriv følgende på dansk. Ingen markdown-formatering (ingen **, ---, #). Returner SKIP i en sektion, hvis der intet er at sige.",
        "LINKEDIN = 2-4 sætninger, ingen preamble, dækker det relevante fra ovenstående commits.",
        "FOUNDER = 3-6 sætninger, kun med reel pointe.",
        "",
        "LINKEDIN:",
        "...",
        "",
        "FOUNDER:",
        "...",
    ]);

    $output = callMistral($systemMsg, $batchUserMsg);

    if ($output === null) {
        error_log("Batch Mistral call failed for {$n} notable event(s)");
    } else {
        $linkedin = '';
        $founder  = '';

        if (preg_match('/LINKEDIN:(.*?)(?=FOUNDER:|$)/s', $output, $m)) {
            $linkedin = stripMarkers($m[1]);
        }
        if (preg_match('/FOUNDER:(.*)/s', $output, $m)) {
            $founder = stripMarkers($m[1]);
        }

        if ($linkedin !== '' && strtolower($linkedin) !== 'skip') {
            insertDraft($pdo, $anchorEvent['id'], 'linkedin_post', $linkedin, $batchEventIds);
        }
        if ($founder !== '' && strtolower($founder) !== 'skip') {
            insertDraft($pdo, $anchorEvent['id'], 'founder_update', $founder, $batchEventIds);
        }

        echo "Batch ({$n} commit" . ($n !== 1 ? 's' : '') . "): LinkedIn + Founder → event #{$anchorEvent['id']}\n";
    }
}

echo "AI draft generation completed\n";
