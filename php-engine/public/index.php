<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Security/CommandAllowlist.php';
require_once __DIR__ . '/../src/Discovery/WindowsUsbScanner.php';

use MspTools\Security\CommandAllowlist;
use MspTools\Discovery\WindowsUsbScanner;

header('Content-Type: application/json; charset=utf-8');

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && $uri === '/api/v1/health') {
    echo json_encode([
        'ok'         => true,
        'service'    => 'MSP Tools PHP Engine',
        'phpVersion' => PHP_VERSION,
        'phase'      => 'Phase 1 - Foundation & Discovery'
    ]);
    exit;
}

if ($method === 'GET' && $uri === '/api/v1/devices/scan') {
    $adb = CommandAllowlist::run('adb_devices');
    $fastboot = CommandAllowlist::run('fastboot_devices');
    $usb = (new WindowsUsbScanner())->scan();

    echo json_encode([
        'ok'        => true,
        'adb'       => $adb,
        'fastboot'  => $fastboot,
        'usbModes'  => $usb,
        'scannedAt' => gmdate('c')
    ]);
    exit;
}

if ($method === 'POST' && $uri === '/api/v1/jobs/execute') {
    $payload = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $jobId = (string)($payload['jobId'] ?? '');
    $brand = (string)($payload['brand'] ?? 'Unknown');
    $model = (string)($payload['model'] ?? 'Unknown');

    $logs = [
        "Job [{$jobId}] dispatched to PHP Engine ({$brand} / {$model})",
        'Scanning USB / ADB / Fastboot interfaces...',
    ];

    $usb = (new WindowsUsbScanner())->scan();
    $adb = CommandAllowlist::run('adb_devices');
    $fastboot = CommandAllowlist::run('fastboot_devices');

    if (!empty($usb['devices'])) {
        foreach ($usb['devices'] as $dev) {
            $logs[] = "Detected {$dev['chipset']} -> {$dev['mode']} ({$dev['name']})";
        }
    } else {
        $logs[] = 'No EDL / BROM / Odin hardware port active on USB.';
    }

    $logs[] = $adb['ok'] && $adb['output'] !== ''
        ? 'ADB Interface: ' . str_replace(["\r", "\n"], ' | ', $adb['output'])
        : 'ADB Interface: No authorized ADB device found.';

    $logs[] = $fastboot['ok'] && $fastboot['output'] !== ''
        ? 'Fastboot Interface: ' . str_replace(["\r", "\n"], ' | ', $fastboot['output'])
        : 'Fastboot Interface: No Fastboot device found.';

    $logs[] = 'Phase 1 read-only diagnostic check completed.';

    echo json_encode([
        'ok'        => true,
        'logs'      => $logs,
        'timestamp' => gmdate('c')
    ]);
    exit;
}

http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'Endpoint not found']);
