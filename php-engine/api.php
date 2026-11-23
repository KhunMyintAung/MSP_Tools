<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Security/CommandAllowlist.php';
require_once __DIR__ . '/src/Discovery/WindowsUsbScanner.php';
require_once __DIR__ . '/src/Discovery/DeviceIdentityReader.php';

use MspTools\Security\CommandAllowlist;
use MspTools\Discovery\WindowsUsbScanner;
use MspTools\Discovery\DeviceIdentityReader;

header('Content-Type: application/json; charset=utf-8');
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($uri === '/api/health') {
    echo json_encode([
        'ok'     => true,
        'engine' => 'PHP ' . PHP_VERSION,
        'phase'  => 'Auto-Detect Active'
    ]);
    exit;
}

if ($uri === '/api/device-info') {
    $fullUsb = ($_GET['usb'] ?? '0') === '1';
    echo json_encode([
        'ok'     => true,
        'device' => (new DeviceIdentityReader())->read($fullUsb)
    ]);
    exit;
}

if ($uri === '/api/scan') {
    echo json_encode([
        'ok'        => true,
        'adb'       => CommandAllowlist::run('adb_devices'),
        'fastboot'  => CommandAllowlist::run('fastboot_devices'),
        'usbModes'  => (new WindowsUsbScanner())->scan(),
        'device'    => (new DeviceIdentityReader())->read(true),
        'scannedAt' => gmdate('c')
    ]);
    exit;
}

if ($uri === '/api/execute' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $jobId = (string)($payload['jobId'] ?? 'read-info');
    $jobTitle = (string)($payload['jobTitle'] ?? 'Diagnostic Job');

    $logs = [
        'Engine: PHP ' . PHP_VERSION,
        "Executing Task: {$jobTitle}..."
    ];

    $identity = (new DeviceIdentityReader())->read(true);

    if (empty($identity['connected'])) {
        $logs[] = '[WARN] No active ADB / Fastboot / EDL / BROM device connected.';
        echo json_encode(['ok' => true, 'device' => $identity, 'logs' => $logs]);
        exit;
    }

    $logs[] = "[ACTIVE MODE: {$identity['mode']}] Serial: {$identity['serial']} ({$identity['comPort']})";
    $logs[] = "  • Brand / Platform     : {$identity['brand']}";
    $logs[] = "  • Model / Product      : {$identity['model']}";
    $logs[] = "  • Codename             : {$identity['codename']}";
    $logs[] = "  • Chipset / SoC        : {$identity['chipset']}";
    $logs[] = "  • OS / Mode            : {$identity['androidVersion']}";
    $logs[] = "  • Active Slot          : {$identity['slot']}";
    $logs[] = "  • Bootloader State     : {$identity['bootloader']}";
    if (($identity['antiRollback'] ?? '-') !== '-') {
        $logs[] = "  • Anti-Rollback (ARB)  : {$identity['antiRollback']}";
    }

    if ($jobId === 'read-info' && $identity['mode'] === 'ADB') {
        $partRes = CommandAllowlist::run('adb_partitions');
        if ($partRes['ok'] && $partRes['output'] !== '') {
            $lines = array_slice(array_filter(preg_split('/\r\n|\r|\n/', $partRes['output'])), 0, 12);
            $logs[] = '  • Block Partitions (Top entries):';
            foreach ($lines as $l) {
                $logs[] = "      {$l}";
            }
        }
    }

    $logs[] = '[OK] Operation completed.';
    echo json_encode(['ok' => true, 'device' => $identity, 'logs' => $logs]);
    exit;
}

http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'API endpoint not found']);
