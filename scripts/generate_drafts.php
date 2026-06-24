<?php

require_once __DIR__ . "/env.php";
loadEnv(__DIR__ . "/../.env");

require_once __DIR__ . "/content_safety.php";

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
// Mistral call
// -------------------------------------------------------
function callMistral(string $systemMsg, string $userMsg): ?string
{
    $apiKey = $_ENV['MISTRAL_API_KEY'];
    $model  = $_ENV['MISTRAL_MODEL'] ?? 'mistral-small-latest';

    $data = [
        "model"       => $model,
        "messages"    => [
            ["role" => "system", "content" => $systemMsg],
            ["role" => "user",   "content" => $userMsg],
        ],
        "temperature" => 0.7,
    ];

    $ch = curl_init("https://api.mistral.ai/v1/chat/completions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $apiKey",
        "Content-Type: application/json",
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("Mistral curl error: $curlError");
        return null;
    }

    $json = json_decode($response, true);
    $content = $json['choices'][0]['message']['content'] ?? null;
    return ($content !== null && trim($content) !== '') ? $content : null;
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
// Strip stray markdown markers from generated content
// -------------------------------------------------------
function stripMarkers(string $text): string
{
    $text = preg_replace('/^\s*(\*\*|---|###|##|#)\s*/m', '', $text);
    $text = preg_replace('/\s*(\*\*|---)\s*$/m', '', $text);
    return trim($text);
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

function insertDraft(PDO $pdo, int $eventId, string $type, string $content): void
{
    if (draftExists($pdo, $eventId, $type)) return;
    $check   = classifyDraft($content, $type);
    $status  = $check['severity'] === 'block' ? 'blocked' : 'draft';
    $reasons = empty($check['reasons']) ? null : json_encode($check['reasons']);
    $stmt = $pdo->prepare("INSERT INTO ai_drafts (event_id, type, content, status, safety_severity, safety_reasons) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$eventId, $type, $content, $status, $check['severity'], $reasons]);
}

function markProcessed(PDO $pdo, int $eventId, int $status): void
{
    $pdo->prepare("UPDATE repo_events SET processed = ? WHERE id = ?")->execute([$status, $eventId]);
}

function incrementRetry(PDO $pdo, int $eventId): void
{
    $pdo->prepare("UPDATE repo_events SET retry_count = retry_count + 1 WHERE id = ?")->execute([$eventId]);
    // If this exhausts retries, mark as error on the next check — no separate call needed
    // because the SELECT filters retry_count < MAX_RETRIES
    $stmt = $pdo->prepare("SELECT retry_count FROM repo_events WHERE id = ?");
    $stmt->execute([$eventId]);
    $row = $stmt->fetch();
    if ($row && $row['retry_count'] >= MAX_RETRIES) {
        markProcessed($pdo, $eventId, 3);
    }
}

// -------------------------------------------------------
// System message — voice + banned phrases
// -------------------------------------------------------
$systemMsg = <<<SYS
Du er Lars. Du skriver om dit arbejde på Fellis — en europæisk social platform.
Skriv dansk. Tærslen for at sige noget er høj: skriv kun, hvis der er noget konkret at sige.
Ingen buzzwords. Ingen sætninger der starter med "Det er ikke...". Ingen "men det er nødvendigt".
Ingen refleksioner over tillid, langsigtet tænkning eller "det handler om mere end kode".
Brug ikke disse vendinger: "det er ikke glamourøst", "små skridt men vigtige", "transparent", "autentisk", "deler gerne".
Ingen åbninger som "Spændende nyt", "Vi er glade for" eller "I dag kan vi fortælle".
SYS;

// -------------------------------------------------------
// Main loop
// -------------------------------------------------------
foreach ($events as $event) {
    $classification = classifyCommit($event['message']);

    if ($classification === 'skip') {
        echo "Skip (noise): {$event['commit_hash']} — {$event['message']}\n";
        markProcessed($pdo, $event['id'], 2);
        continue;
    }

    if ($classification === 'changelog') {
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

    } else {
        // notable — request all three types
        $userMsg = implode("\n", [
            "COMMIT:",
            "- Besked: {$event['message']}",
            "- Forfatter: {$event['author']}",
            "",
            "Skriv følgende på dansk. Ingen markdown-formatering (ingen **, ---, #). Returner SKIP i en sektion, hvis der intet er at sige.",
            "Længdegrænser: CHANGELOG = 1-2 linjer faktuel tekst. LINKEDIN = 2-4 sætninger, ingen preamble. FOUNDER = 3-6 sætninger, kun med reel pointe.",
            "",
            "CHANGELOG:",
            "...",
            "",
            "LINKEDIN:",
            "...",
            "",
            "FOUNDER:",
            "...",
        ]);

        $output = callMistral($systemMsg, $userMsg);

        if ($output === null) {
            error_log("Mistral failed for event {$event['id']}");
            incrementRetry($pdo, $event['id']);
            continue;
        }

        $changelog = '';
        $linkedin  = '';
        $founder   = '';

        if (preg_match('/CHANGELOG:(.*?)(?=LINKEDIN:|FOUNDER:|$)/s', $output, $m)) {
            $changelog = stripMarkers($m[1]);
        }
        if (preg_match('/LINKEDIN:(.*?)(?=FOUNDER:|$)/s', $output, $m)) {
            $linkedin = stripMarkers($m[1]);
        }
        if (preg_match('/FOUNDER:(.*)/s', $output, $m)) {
            $founder = stripMarkers($m[1]);
        }

        // If CHANGELOG is missing the response is unparseable — retry
        if ($changelog === '') {
            error_log("Parse failure for event {$event['id']}");
            incrementRetry($pdo, $event['id']);
            continue;
        }

        $inserted = 0;

        if ($changelog !== '' && strtolower($changelog) !== 'skip') {
            insertDraft($pdo, $event['id'], 'changelog', $changelog);
            $inserted++;
        }
        if ($linkedin !== '' && strtolower($linkedin) !== 'skip') {
            insertDraft($pdo, $event['id'], 'linkedin_post', $linkedin);
            $inserted++;
        }
        if ($founder !== '' && strtolower($founder) !== 'skip') {
            insertDraft($pdo, $event['id'], 'founder_update', $founder);
            $inserted++;
        }

        markProcessed($pdo, $event['id'], 1);
        echo "Notable ($inserted draft" . ($inserted !== 1 ? 's' : '') . "): {$event['commit_hash']}\n";
    }
}

echo "AI draft generation completed\n";
