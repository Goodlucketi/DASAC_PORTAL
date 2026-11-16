<?php
declare(strict_types=1);

function defaultPolicy(): array
{
    return [
        'minAttendance' => 75,
        'passMark' => 50,
        'testWeight' => 30,
        'examWeight' => 70,
    ];
}

function loadPolicy(): array
{
    $default = defaultPolicy();
    $path = dirname(__DIR__, 2) . '/storage/policies.json';
    if (!is_file($path)) {
        return $default;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return $default;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $default;
    }
    foreach ($default as $key => $value) {
        if (array_key_exists($key, $data) && is_numeric($data[$key])) {
            $default[$key] = (float) $data[$key];
        }
    }
    return $default;
}

function savePolicy(array $policy): void
{
    $path = dirname(__DIR__, 2) . '/storage/policies.json';
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Policy storage directory is unavailable.');
    }

    $payload = array_merge(defaultPolicy(), $policy);
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException('Policy could not be saved.');
    }
    if (file_put_contents($path, $encoded . PHP_EOL) === false) {
        throw new RuntimeException('Policy file could not be written.');
    }
}
