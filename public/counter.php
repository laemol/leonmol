<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function getClientIp(): string
{
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']);
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }

    return trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function isBotRequest(): bool
{
    $userAgent = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

    if ($userAgent === '') {
        return true;
    }

    return (bool) preg_match('/bot|crawl|spider|slurp|curl|wget|python|axios|httpclient|uptime|monitor/', $userAgent);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$counterFile = __DIR__ . '/counter.txt';
$hitsFile = __DIR__ . '/counter_hits.json';
$cooldownInSeconds = 60 * 60 * 6;

if (!file_exists($counterFile)) {
    file_put_contents($counterFile, "0\n", LOCK_EX);
}

if (!file_exists($hitsFile)) {
    file_put_contents($hitsFile, "{}\n", LOCK_EX);
}

$shouldIncrement = $method === 'POST' && !isBotRequest();

if ($shouldIncrement) {
    $clientIp = getClientIp();
    $now = time();

    $hitsHandle = fopen($hitsFile, 'c+');

    if ($hitsHandle !== false && flock($hitsHandle, LOCK_EX)) {
        rewind($hitsHandle);
        $rawHits = trim((string) stream_get_contents($hitsHandle));
        $hits = json_decode($rawHits !== '' ? $rawHits : '{}', true);

        if (!is_array($hits)) {
            $hits = [];
        }

        $lastHit = isset($hits[$clientIp]) ? (int) $hits[$clientIp] : 0;

        if ($lastHit > 0 && ($now - $lastHit) < $cooldownInSeconds) {
            $shouldIncrement = false;
        } else {
            $hits[$clientIp] = $now;

            rewind($hitsHandle);
            ftruncate($hitsHandle, 0);
            fwrite($hitsHandle, json_encode($hits, JSON_UNESCAPED_SLASHES) . PHP_EOL);
            fflush($hitsHandle);
        }

        flock($hitsHandle, LOCK_UN);
        fclose($hitsHandle);
    } else {
        $shouldIncrement = false;
        if ($hitsHandle !== false) {
            fclose($hitsHandle);
        }
    }
}

$file = fopen($counterFile, 'c+');

if ($file === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Counterbestand kon niet geopend worden']);
    exit;
}

if (!flock($file, LOCK_EX)) {
    fclose($file);
    http_response_code(500);
    echo json_encode(['error' => 'Counterbestand kon niet vergrendeld worden']);
    exit;
}

rewind($file);
$rawValue = trim((string) stream_get_contents($file));
$value = ctype_digit($rawValue) ? (int) $rawValue : 0;

if ($shouldIncrement) {
    $value++;

    rewind($file);
    ftruncate($file, 0);
    fwrite($file, (string) $value . PHP_EOL);
    fflush($file);
}

flock($file, LOCK_UN);
fclose($file);

echo json_encode([
    'value' => $value,
    'incremented' => $shouldIncrement,
]);
