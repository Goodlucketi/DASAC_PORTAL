<?php
declare(strict_types=1);

function defaultSystemSettings(): array
{
    return [
        'attendance' => ['maxFailedAttempts' => 5, 'windowMinutes' => 30],
        'transferReasons' => [
            ['label' => 'Change of interest', 'requiresDocument' => false],
            ['label' => 'Relocation', 'requiresDocument' => true],
            ['label' => 'Health or accessibility needs', 'requiresDocument' => true],
            ['label' => 'Other', 'requiresDocument' => false],
        ],
        'certificate' => ['issuer' => 'SkillPortal', 'serialPrefix' => 'SP-CERT', 'nextSerial' => 1, 'wording' => '', 'signatureName' => '', 'logoUrl' => '', 'qrVerifyBaseUrl' => ''],
        'notifications' => [
            'channels' => ['email' => false, 'sms' => false, 'push' => false],
            'templates' => ['announcement' => 'A new announcement has been posted: {{title}}', 'result' => 'Your result for {{course}} is now available.'],
        ],
    ];
}

function loadSystemSettings(): array
{
    $defaults = defaultSystemSettings();
    $path = dirname(__DIR__, 2) . '/storage/superadmin.json';
    $raw = is_file($path) ? @file_get_contents($path) : false;
    $saved = $raw === false ? null : json_decode($raw, true);
    if (!is_array($saved)) return $defaults;
    foreach ($defaults as $key => $value) {
        if (isset($saved[$key]) && is_array($saved[$key])) {
            $defaults[$key] = array_replace($value, $saved[$key]);
        }
    }
    return $defaults;
}

function saveSystemSettings(array $settings): void
{
    $path = dirname(__DIR__, 2) . '/storage/superadmin.json';
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('System settings directory is unavailable.');
    }
    $encoded = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($encoded === false || file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('System settings could not be saved.');
    }
}