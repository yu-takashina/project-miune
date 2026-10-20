<?php

declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=UTF-8');

const SEARCH_RATE_LIMIT = 20;

const SEARCH_RATE_WINDOW_SECONDS = 60;

$retryAfter = search_retry_after(client_address());

if ($retryAfter !== null) {
    header('Retry-After: '.$retryAfter);
    respond(429, ['message' => 'リクエストが多すぎます。']);
}

$apiKey = env_string('API_KEY');
$apiBase = env_string('API_BASE');

if ($apiKey === '') {
    respond(500, ['message' => 'APIキーが未設定です。']);
}

if ($apiBase === '') {
    respond(500, ['message' => '検索APIの接続先が未設定です。']);
}

$rawQuery = $_GET['q'] ?? '';
$query = trim(is_string($rawQuery) ? $rawQuery : '');
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
    /** @var array<string, string>|null $values */
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

function client_address(): string
{
    $address = $_SERVER['REMOTE_ADDR'] ?? '';

    if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
        return '';
    }

    return $address;
}

/**
 * 同一IPの直近のアクセスが上限の回数を超えたら、再試行までの秒数を返す。
 * 記録してから数える。同時に来たアクセスが上限をすり抜けないようにする。
 */
function search_retry_after(string $address): ?int
{
    $path = sys_get_temp_dir().'/miune-search-rate.json';
    $handle = fopen($path, 'c+');

    if ($handle === false) {
        return null;
    }

    try {
        if (! flock($handle, LOCK_EX)) {
            return null;
        }

        $now = time();
        $records = rate_records($handle, $now);
        $key = hash('sha256', $address);
        $hits = $records[$key] ?? [];
        $hits[] = $now;
        $retryAfter = null;

        if (count($hits) > SEARCH_RATE_LIMIT) {
            $hits = array_slice($hits, -SEARCH_RATE_LIMIT);
            $retryAfter = max(1, $hits[0] + SEARCH_RATE_WINDOW_SECONDS - $now);
        }

        $records[$key] = $hits;
        $encoded = json_encode($records);

        if (is_string($encoded)) {
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, $encoded);
            fflush($handle);
        }

        return $retryAfter;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * @param  resource  $handle
 * @return array<string, list<int>>
 */
function rate_records($handle, int $now): array
{
    rewind($handle);
    $raw = stream_get_contents($handle);

    if (! is_string($raw) || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    if (! is_array($decoded)) {
        return [];
    }

    $threshold = $now - SEARCH_RATE_WINDOW_SECONDS;
    $records = [];

    foreach ($decoded as $key => $hits) {
        if (! is_string($key) || ! is_array($hits)) {
            continue;
        }

        $recent = [];

        foreach ($hits as $hit) {
            if (is_int($hit) && $hit > $threshold) {
                $recent[] = $hit;
            }
        }

        if ($recent !== []) {
            $records[$key] = $recent;
        }
    }

    return $records;
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
