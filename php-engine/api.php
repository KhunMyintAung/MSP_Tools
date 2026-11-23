<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Security/CommandAllowlist.php';
require_once __DIR__ . '/src/Discovery/WindowsUsbScanner.php';

use MspTools\Security\CommandAllowlist;
use MspTools\Discovery\WindowsUsbScanner;

header('Content-Type: application/json; charset=utf-8');
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($uri === '/api/health') {
    echo json_encode([
        'ok'     => true,
        'engine' => 'PHP ' . PHP_VERSION,
        'os'     => PHP_OS_FAMILY
    ]);
    exit;
}

if ($uri === '/api/scan') {
    echo json_encode([
        'ok'        => true,
        'adb'       => CommandAllowlist::run('adb_devices'),
        'fastboot'  => CommandAllowlist::run('fastboot_devices'),
        'usbModes'  => (new WindowsUsbScanner())->scan(),
        'scannedAt' => gmdate('c')
    ]);
    exit;
}

if ($uri === '/api/execute' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $jobTitle = (string)($payload['jobTitle'] ?? 'Diagnostic Job');
    $brand = (string)($payload['brand'] ?? 'Unknown');
    $model = (string)($payload['model'] ?? 'Unknown');

    $logs = [
        "Dispatched [{$jobTitle}] to PHP Engine (" . PHP_VERSION . ")",
        "Target Profile: {$brand} -> {$model}",
        'Checking USB Hardware Ports (EDL / BROM / Preloader / Odin / SPD)...',
    ];

    $usb = (new WindowsUsbScanner())->scan();
    $adb = CommandAllowlist::run('adb_devices');
    $fastboot = CommandAllowlist::run('fastboot_devices');

    if (!empty($usb['devices'])) {
        foreach ($usb['devices'] as $dev) {
            $logs[] = "FOUND USB PORT: {$dev['chipset']} | {$dev['mode']} -> {$dev['name']}";
        }
    } else {
        $logs[] = 'USB Hardware Ports: No EDL / BROM / Odin port detected.';
    }

    $logs[] = $adb['ok'] && $adb['output'] !== ''
        ? 'ADB Interface: ' . str_replace(["\r", "\n"], ' | ', $adb['output'])
        : 'ADB Interface: ' . ($adb['error'] ?: 'No ADB device connected.');

    $logs[] = $fastboot['ok'] && $fastboot['output'] !== ''
        ? 'Fastboot Interface: ' . str_replace(["\r", "\n"], ' | ', $fastboot['output'])
        : 'Fastboot Interface: ' . ($fastboot['error'] ?: 'No Fastboot device connected.');

    $logs[] = 'Operation finished successfully (Phase 1 Read-Only Safe Mode).';

    echo json_encode(['ok' => true, 'logs' => $logs]);
    exit;
}

http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'API endpoint not found']);
