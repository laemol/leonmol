<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$counterFile = __DIR__ . '/counter.txt';

if (!file_exists($counterFile)) {
    file_put_contents($counterFile, "0\n", LOCK_EX);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $value++;

    rewind($file);
    ftruncate($file, 0);
    fwrite($file, (string) $value . PHP_EOL);
    fflush($file);
}

flock($file, LOCK_UN);
fclose($file);

echo json_encode(['value' => $value]);
