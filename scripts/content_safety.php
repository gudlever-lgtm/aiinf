<?php
// Content safety classifier. Requires $_ENV populated via loadEnv() before calling.
// classifyDraft() runs a keyword layer then a Mistral LLM layer.
// Fails closed on any error: unknown outcome → 'flag', never 'ok'.

function classifyDraft(string $content, string $type): array
{
    $keyword = _safety_keyword($content);
    if ($keyword['severity'] === 'block') {
        return $keyword;
    }

    $llm = _safety_mistral($content, $type);

    if ($llm['severity'] === 'flag' || $keyword['severity'] === 'flag') {
        return [
            'safe'     => false,
            'reasons'  => array_merge($keyword['reasons'], $llm['reasons']),
            'severity' => 'flag',
        ];
    }

    return ['safe' => true, 'reasons' => [], 'severity' => 'ok'];
}

function _safety_keyword(string $content): array
{
    $reasons = [];

    $block = [
        ['/\bsecurity\s+audit\b/i',           'Security audit disclosure'],
        ['/\bsikkerhedsaudit\b/i',             'Security audit disclosure (DA)'],
        ['/\bs[aå]rbarhed\b/i',                'Vulnerability disclosure (DA)'],
        ['/\bvulnerabilit(y|ies)\b/i',         'Vulnerability disclosure'],
        ['/\bCVE-\d/i',                        'CVE reference'],
        ['/\baudit\s+finding(s)?\b/i',         'Audit finding disclosure'],
        ['/\bpenetration\s+test(ing)?\b/i',    'Pentest disclosure'],
        ['/\bpentest\b/i',                     'Pentest disclosure'],
        ['/\bexploit\b/i',                     'Exploit reference'],
        ['/\bdisclosure\b/i',                  'Security disclosure reference'],
        ['/\bClaude\b/',                       'AI authorship tell (Claude)'],
        ['/\bAnthropic\b/i',                   'AI authorship tell (Anthropic)'],
        ['/\bAI-genereret\b/i',                'AI authorship tell (DA)'],
        ['/\bskrevet\s+af\s+(en\s+)?AI\b/i',   'AI authorship tell (DA)'],
        ['/\bgenereret\s+af\s+(en\s+)?AI\b/i', 'AI authorship tell (DA)'],
        ['/claude\/[a-z]/i',                   'Branch name leakage (AI authorship)'],
        ['/\bCLA\s+automation\b/i',            'Internal process disclosure (CLA)'],
        ['/\bcontributor\s+license\b/i',       'Internal process disclosure (CLA)'],
        ['/\bCI\s+pipeline\b/i',               'Internal process disclosure (CI)'],
        ['/\bGitHub\s+Actions\b/i',            'Internal process disclosure (GitHub Actions)'],
        ['/\bworkflow\s+run\b/i',              'Internal process disclosure (workflow run)'],
        ['/\bpre-commit\s+hook\b/i',           'Internal process disclosure (hook)'],
        ['/\bunreleased\b/i',                  'Unreleased feature disclosure'],
        ['/\bikke\s+frigivet\b/i',             'Unreleased feature disclosure (DA)'],
        ['/\binternal\s+only\b/i',             'Internal-only content'],
        ['/\bintern\s+brug\b/i',               'Internal-only content (DA)'],
        ['/\bikke\s+offentliggjort\b/i',       'Unpublished content disclosure (DA)'],
        ['/\bintentional\s+skip\b/i',          'Intentional skip marker'],
        ['/\bbevidst\s+spring\s+over\b/i',     'Intentional skip marker (DA)'],
    ];

    foreach ($block as [$pattern, $reason]) {
        if (preg_match($pattern, $content)) {
            $reasons[] = $reason;
        }
    }

    if ($reasons) {
        return ['safe' => false, 'reasons' => $reasons, 'severity' => 'block'];
    }

    $flag = [
        ['/\bWIP\b/',                     'Work in progress marker'],
        ['/\bwork\s+in\s+progress\b/i',   'Work in progress marker'],
        ['/\bcoming\s+soon\b/i',          'Upcoming feature hint'],
        ['/\bsnart\b/i',                  'Upcoming feature hint (DA)'],
        ['/\b[0-9a-f]{7,40}\b/i',         'Raw commit hash (may leak internal ref)'],
    ];

    foreach ($flag as [$pattern, $reason]) {
        if (preg_match($pattern, $content)) {
            $reasons[] = $reason;
        }
    }

    if ($reasons) {
        return ['safe' => false, 'reasons' => $reasons, 'severity' => 'flag'];
    }

    return ['safe' => true, 'reasons' => [], 'severity' => 'ok'];
}

function _safety_mistral(string $content, string $type): array
{
    $apiKey = $_ENV['MISTRAL_API_KEY'] ?? null;
    if (!$apiKey) {
        error_log('[content_safety] MISTRAL_API_KEY not set — failing closed');
        return ['safe' => false, 'reasons' => ['Mistral API key not configured'], 'severity' => 'flag'];
    }

    $system = 'You are a strict content safety classifier for a startup\'s public communications. '
        . 'Classify the draft and return ONLY valid JSON: {"safe": true, "reasons": []} or {"safe": false, "reasons": [...]}. '
        . 'Set safe=false if the text: discloses security vulnerabilities, audit findings, or security posture; '
        . 'reveals AI authorship (Claude, Anthropic, AI-generated text); '
        . 'exposes internal development process (CI/CD, branch names, contributor agreements, automation tooling); '
        . 'references unreleased or internal-only features. '
        . 'Return {"safe": true, "reasons": []} if the text is safe for public publication. '
        . 'Return ONLY the JSON object. No markdown. No explanation.';

    $data = [
        'model'       => $_ENV['MISTRAL_MODEL'] ?? 'mistral-small-latest',
        'messages'    => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => "Draft type: {$type}\n\n{$content}"],
        ],
        'temperature' => 0.0,
        'max_tokens'  => 256,
    ];

    $ch = curl_init('https://api.mistral.ai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $response === false) {
        error_log('[content_safety] Mistral unreachable: ' . $curlError);
        return ['safe' => false, 'reasons' => ['Mistral API unreachable'], 'severity' => 'flag'];
    }

    $outer = json_decode($response, true);
    $text  = $outer['choices'][0]['message']['content'] ?? null;

    if (!$text) {
        error_log('[content_safety] Mistral empty response');
        return ['safe' => false, 'reasons' => ['Mistral returned empty response'], 'severity' => 'flag'];
    }

    $result = json_decode(trim($text), true);
    if (!is_array($result) || !array_key_exists('safe', $result)) {
        error_log('[content_safety] Mistral parse failure: ' . $text);
        return ['safe' => false, 'reasons' => ['Mistral response parse failure'], 'severity' => 'flag'];
    }

    if (!$result['safe']) {
        $reasons = array_values(array_filter((array)($result['reasons'] ?? [])));
        return [
            'safe'     => false,
            'reasons'  => $reasons ?: ['LLM classifier flagged content'],
            'severity' => 'flag',
        ];
    }

    return ['safe' => true, 'reasons' => [], 'severity' => 'ok'];
}
