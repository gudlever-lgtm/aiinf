<?php

require_once __DIR__ . "/env.php";
loadEnv(__DIR__ . "/../.env");

// -----------------------------
// DB
// -----------------------------
$pdo = new PDO(
    "mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['DB_NAME'] . ";charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
);

// -----------------------------
// FETCH NEW EVENTS
// -----------------------------
$stmt = $pdo->query("
    SELECT *
    FROM repo_events
    WHERE id NOT IN (
        SELECT event_id FROM ai_drafts WHERE event_id IS NOT NULL
    )
    ORDER BY created_at ASC
");

$events = $stmt->fetchAll();

if (!$events) {
    exit("No new events\n");
}

// -----------------------------
// MISTRAL CALL FUNCTION
// -----------------------------
function callMistral($prompt)
{
    $apiKey = $_ENV['MISTRAL_API_KEY'];
    $model = $_ENV['MISTRAL_MODEL'] ?? 'mistral-small-latest';

    $data = [
        "model" => $model,
        "messages" => [
            [
                "role" => "system",
                "content" => "Du er en produkt- og kommunikationsassistent for Fellis. Skriv præcist, uden hype, og fokuser på reel værdi."
            ],
            [
                "role" => "user",
                "content" => $prompt
            ]
        ],
        "temperature" => 0.4
    ];

    $ch = curl_init("https://api.mistral.ai/v1/chat/completions");

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $apiKey,
        "Content-Type: application/json"
    ]);

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($response, true);

    return $json['choices'][0]['message']['content'] ?? "";
}

// -----------------------------
// PROCESS EVENTS
// -----------------------------
foreach ($events as $event) {

    $prompt = "
Du analyserer en Git commit fra Fellis.

FELLIS BESKRIVELSE:
Fellis er en europæisk social platform baseret på transparens, privatliv og ikke-algoritmisk feed.

COMMIT:
- Hash: {$event['commit_hash']}
- Author: {$event['author']}
- Message: {$event['message']}

OPGAVE:
Generér følgende 3 outputs:

1. CHANGELOG (kort og teknisk)
2. LINKEDIN POST (professionel, ingen hype)
3. FOUNDER UPDATE (reflekterende, ærlig)

REGLER:
- ingen overdrivelse
- ingen marketingfluff
- vær konkret
- hvis commit er lille → skriv det som lille ændring
- hvis commit er teknisk → forklar enkel værdi

FORMAT:

CHANGELOG:
...

LINKEDIN:
...

FOUNDER:
...
";

    $aiOutput = callMistral($prompt);

    // -----------------------------
    // PARSE OUTPUT
    // -----------------------------
    $changelog = "";
    $linkedin = "";
    $founder = "";

    if (preg_match('/CHANGELOG:(.*?)LINKEDIN:/s', $aiOutput, $m)) {
        $changelog = trim($m[1]);
    }

    if (preg_match('/LINKEDIN:(.*?)FOUNDER:/s', $aiOutput, $m)) {
        $linkedin = trim($m[1]);
    }

    if (preg_match('/FOUNDER:(.*)/s', $aiOutput, $m)) {
        $founder = trim($m[1]);
    }

    // fallback hvis parsing fejler
    if (!$changelog) $changelog = $aiOutput;

    // -----------------------------
    // SAVE TO DB
    // -----------------------------
    $stmt = $pdo->prepare("
        INSERT INTO ai_drafts (event_id, type, content, status)
        VALUES (?, ?, ?, ?)
    ");

    $stmt->execute([$event['id'], "changelog", $changelog, "draft"]);
    $stmt->execute([$event['id'], "linkedin_post", $linkedin, "draft"]);
    $stmt->execute([$event['id'], "founder_update", $founder, "draft"]);
}

echo "AI draft generation completed\n";

