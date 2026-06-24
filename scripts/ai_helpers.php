<?php

// Shared Mistral helpers used by generate_drafts.php and regenerate_draft.php

function callMistral(string $systemMsg, string $userMsg, float $temperature = 0.7): ?string
{
    $apiKey = $_ENV['MISTRAL_API_KEY'];
    $model  = $_ENV['MISTRAL_MODEL'] ?? 'mistral-small-latest';

    $data = [
        "model"       => $model,
        "messages"    => [
            ["role" => "system", "content" => $systemMsg],
            ["role" => "user",   "content" => $userMsg],
        ],
        "temperature" => $temperature,
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

    $json    = json_decode($response, true);
    $content = $json['choices'][0]['message']['content'] ?? null;
    return ($content !== null && trim($content) !== '') ? $content : null;
}

function stripMarkers(string $text): string
{
    $text = preg_replace('/^\s*(\*\*|---|###|##|#)\s*/m', '', $text);
    $text = preg_replace('/\s*(\*\*|---)\s*$/m', '', $text);
    return trim($text);
}

function getFewShotExamples(PDO $pdo, string $type, int $limit = 2): array
{
    $stmt = $pdo->prepare(
        "SELECT content FROM ai_drafts
         WHERE type = ? AND status IN ('approved', 'published')
         ORDER BY id DESC LIMIT ?"
    );
    // LIMIT must be bound as an integer. With emulated prepares (the MySQL/MariaDB
    // default) execute([...]) binds every value as a string, producing LIMIT '2',
    // which is a SQL syntax error.
    $stmt->bindValue(1, $type, PDO::PARAM_STR);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function buildSystemMsg(PDO $pdo): string
{
    $base = <<<SYS
Du er Lars. Du skriver om dit arbejde på Fellis — en europæisk social platform.
Skriv dansk. Tærslen for at sige noget er høj: skriv kun, hvis der er noget konkret at sige.
Ingen buzzwords. Ingen sætninger der starter med "Det er ikke...". Ingen "men det er nødvendigt".
Ingen refleksioner om tillid, langsigtet tænkning eller "det handler om mere end kode".
Brug ikke disse vendinger: "det er ikke glamourøst", "små skridt men vigtige", "transparent", "autentisk", "deler gerne".
Ingen åbninger som "Spændende nyt", "Vi er glade for" eller "I dag kan vi fortælle".
SYS;

    $typeLabels = [
        'changelog'      => 'changelog',
        'linkedin_post'  => 'LinkedIn-opslag',
        'founder_update' => 'founder update',
    ];

    $exampleSections = [];
    foreach ($typeLabels as $type => $label) {
        $examples = getFewShotExamples($pdo, $type);
        if (!$examples) continue;
        $lines = ["Eksempler på godkendte {$label}:"];
        foreach ($examples as $ex) {
            $lines[] = "---\n" . trim($ex);
        }
        $exampleSections[] = implode("\n", $lines);
    }

    if ($exampleSections) {
        $base .= "\n\n" . implode("\n\n", $exampleSections);
    }

    return $base;
}
