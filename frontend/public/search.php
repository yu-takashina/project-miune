<?php

declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=UTF-8');

$apiKey = env_string('API_KEY');
$apiBase = env_string('API_BASE');

if ($apiKey === '') {
    respond(500, ['message' => 'APIキーが未設定です。']);
}

if ($apiBase === '') {
    respond(500, ['message' => '検索APIの接続先が未設定です。']);
}

$query = trim((string) ($_GET['q'] ?? ''));
$url = rtrim($apiBase, '/').'/api/search?'.http_build_query(['q' => $query]);
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Accept: application/json\r\nAuthorization: Bearer {$apiKey}\r\n",
        'timeout' => 170,
        'ignore_errors' => true,
        'follow_location' => 0,
    ],
]);
$body = @file_get_contents($url, false, $context);
$headers = http_get_last_response_headers();

if (! is_string($body) || $headers === null) {
    respond(503, ['message' => '検索APIに接続できません。']);
}

$status = 502;

foreach ($headers as $header) {
    if (preg_match('#\AHTTP/\d+(?:\.\d+)?\s+(\d{3})#', $header, $matches) === 1) {
        $status = (int) $matches[1];
    }

    if (stripos($header, 'Retry-After:') === 0) {
        header($header);
    }
}

http_response_code($status);
echo $body;

function env_string(string $name): string
{
    static $values = null;

    if ($values === null) {
        $values = load_env(dirname(__DIR__).'/.env');
    }

    return $values[$name] ?? '';
}

/**
 * @return array<string, string>
 */
function load_env(string $path): array
{
    if (! is_readable($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        return [];
    }

    $values = [];

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $separator = strpos($line, '=');

        if ($separator === false) {
            continue;
        }

        $key = trim(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));

        if ($key === '') {
            continue;
        }

        $quoted = strlen($value) >= 2
            && (($value[0] === '"' && str_ends_with($value, '"'))
                || ($value[0] === "'" && str_ends_with($value, "'")));

        if ($quoted) {
            $value = substr($value, 1, -1);
        }

        $values[$key] = $value;
    }

    return $values;
}

/**
 * @param  array{message: string}  $body
 */
function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);

    exit;
}
