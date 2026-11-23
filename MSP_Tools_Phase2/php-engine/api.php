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
        'phase'  => 'Phase 2'
    ]);
    exit;
}

if ($uri === '/api/device-info') {
    echo json_encode([
        'ok'     => true,
        'device' => (new DeviceIdentityReader())->read()
    ]);
    exit;
}

if ($uri === '/api/scan') {
    echo json_encode([
        'ok'        => true,
        'adb'       => CommandAllowlist::run('adb_devices'),
        'fastboot'  => CommandAllowlist::run('fastboot_devices'),
        'usbModes'  => (new WindowsUsbScanner())->scan(),
        'device'    => (new DeviceIdentityReader())->read(),
        'scannedAt' => gmdate('c')
    ]);
    exit;
}

if ($uri === '/api/execute' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $jobId = (string)($payload['jobId'] ?? 'read-info');
    $jobTitle = (string)($payload['jobTitle'] ?? 'Diagnostic Job');
    $selectedProfile = ($payload['brand'] ?? '') . ' / ' . ($payload['model'] ?? '');

    $logs = [
        'Engine: PHP ' . PHP_VERSION . ' (Phase 2 Inspector)',
        "Selected Profile: {$selectedProfile}",
        "Executing Task: {$jobTitle}..."
    ];

    $identity = (new DeviceIdentityReader())->read();

    if (empty($identity['connected'])) {
        $logs[] = '[WARN] No active Android / EDL / BROM / Odin device detected on USB.';
        $logs[] = 'Tip: Connect device via USB data cable and click SCAN PORTS.';
        echo json_encode(['ok' => true, 'device' => $identity, 'logs' => $logs]);
        exit;
    }

    $logs[] = "[CONNECTED] Transport: {$identity['transport']} | Port: {$identity['comPort']}";
    $logs[] = "  • Brand / Manufacturer : {$identity['brand']}";
    $logs[] = "  • Market Model         : {$identity['model']}";
    $logs[] = "  • Device Codename      : {$identity['codename']}";
    $logs[] = "  • Chipset / Platform   : {$identity['chipset']}";
    $logs[] = "  • Android / Mode       : {$identity['androidVersion']}";
    $logs[] = "  • Security Patch       : {$identity['securityPatch']}";
    $logs[] = "  • Active Slot          : {$identity['slot']}";
    $logs[] = "  • Bootloader State     : {$identity['bootloader']}";

    if ($jobId === 'read-info' && $identity['transport'] === 'ADB') {
        $partRes = CommandAllowlist::run('adb_partitions');
        if ($partRes['ok'] && $partRes['output'] !== '') {
            $lines = array_slice(array_filter(preg_split('/\r\n|\r|\n/', $partRes['output'])), 0, 12);
            $logs[] = '  • Block Partitions (Top entries):';
            foreach ($lines as $l) {
                $logs[] = "      {$l}";
            }
        }
    }

    $logs[] = '[OK] Phase 2 Real Device Identity inspection completed.';
    echo json_encode(['ok' => true, 'device' => $identity, 'logs' => $logs]);
    exit;
}

http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'API endpoint not found']);
