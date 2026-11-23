<?php
declare(strict_types=1);

namespace MspTools\Discovery;

use MspTools\Security\CommandAllowlist;

final class DeviceIdentityReader
{
    public function read(): array
    {
        $adbList = CommandAllowlist::run('adb_devices');
        $fbList = CommandAllowlist::run('fastboot_devices');
        $usb = (new WindowsUsbScanner())->scan();

        $adbLines = array_values(array_filter(
            array_slice(preg_split('/\r\n|\r|\n/', $adbList['output'] ?? ''), 1),
            fn($l) => trim($l) !== '' && !str_starts_with(trim($l), '*')
        ));

        if (!empty($adbLines)) {
            $parts = preg_split('/\s+/', trim($adbLines[0]));
            $serial = $parts[0] ?? 'unknown';
            $state = $parts[1] ?? 'unknown';

            if ($state === 'device') {
                $propRes = CommandAllowlist::run('adb_getprop');
                $p = $this->parseAdbProps($propRes['output'] ?? '');
                $unlocked = ($p['ro.boot.flash.locked'] ?? '') === '0' || ($p['ro.boot.verifiedbootstate'] ?? '') === 'orange';

                return [
                    'connected'      => true,
                    'transport'      => 'ADB',
                    'serial'         => $serial,
                    'brand'          => strtoupper($p['ro.product.brand'] ?? $p['ro.product.manufacturer'] ?? 'Android'),
                    'model'          => $p['ro.product.marketname'] ?? $p['ro.product.model'] ?? 'Unknown Model',
                    'codename'       => $p['ro.product.device'] ?? $p['ro.build.product'] ?? '-',
                    'chipset'        => $p['ro.board.platform'] ?? $p['ro.hardware'] ?? $p['ro.soc.model'] ?? 'Unknown SoC',
                    'androidVersion' => $p['ro.build.version.release'] ?? '-',
                    'securityPatch'  => $p['ro.build.version.security_patch'] ?? '-',
                    'buildId'        => $p['ro.build.display.id'] ?? $p['ro.build.id'] ?? '-',
                    'slot'           => $p['ro.boot.slot_suffix'] ?? 'non-AB',
                    'bootloader'     => $unlocked ? 'UNLOCKED' : 'LOCKED',
                    'comPort'        => 'ADB-USB'
                ];
            }

            return [
                'connected'      => true,
                'transport'      => 'ADB (' . strtoupper($state) . ')',
                'serial'         => $serial,
                'brand'          => 'ANDROID',
                'model'          => 'Authorize USB Debugging on phone screen',
                'codename'       => '-',
                'chipset'        => '-',
                'androidVersion' => '-',
                'securityPatch'  => '-',
                'buildId'        => '-',
                'slot'           => '-',
                'bootloader'     => 'UNKNOWN',
                'comPort'        => 'ADB-USB'
            ];
        }

        $fbLines = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $fbList['output'] ?? ''),
            fn($l) => trim($l) !== ''
        ));

        if (!empty($fbLines)) {
            $serial = preg_split('/\s+/', trim($fbLines[0]))[0] ?? 'unknown';
            $varRes = CommandAllowlist::run('fastboot_getvar');
            $v = $this->parseFastbootVars(($varRes['stderr'] ?? '') . "\n" . ($varRes['output'] ?? ''));

            return [
                'connected'      => true,
                'transport'      => 'FASTBOOT',
                'serial'         => $serial,
                'brand'          => strtoupper($v['product'] ?? 'FASTBOOT DEVICE'),
                'model'          => $v['product'] ?? 'Bootloader Target',
                'codename'       => $v['product'] ?? $v['variant'] ?? '-',
                'chipset'        => $v['platform'] ?? $v['hw-revision'] ?? 'Fastboot Interface',
                'androidVersion' => 'Bootloader Mode',
                'securityPatch'  => '-',
                'buildId'        => $v['version-bootloader'] ?? '-',
                'slot'           => isset($v['current-slot']) ? '_' . $v['current-slot'] : 'non-AB',
                'bootloader'     => ($v['unlocked'] ?? '') === 'yes' ? 'UNLOCKED' : (($v['unlocked'] ?? '') === 'no' ? 'LOCKED' : 'UNKNOWN'),
                'comPort'        => 'FASTBOOT-USB'
            ];
        }

        if (!empty($usb['devices'])) {
            $d = $usb['devices'][0];
            return [
                'connected'      => true,
                'transport'      => $d['mode'],
                'serial'         => $d['instanceId'],
                'brand'          => strtoupper($d['chipset']),
                'model'          => $d['name'],
                'codename'       => $d['engine'],
                'chipset'        => $d['chipset'],
                'androidVersion' => 'Hardware Service Port',
                'securityPatch'  => '-',
                'buildId'        => $d['status'],
                'slot'           => '-',
                'bootloader'     => 'HARDWARE-LEVEL',
                'comPort'        => $d['comPort']
            ];
        }

        return ['connected' => false];
    }

    private function parseAdbProps(string $raw): array
    {
        $props = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            if (preg_match('/^\[([^\]]+)\]:\s*\[(.*)\]$/', trim($line), $m)) {
                $props[$m[1]] = $m[2];
            }
        }
        return $props;
    }

    private function parseFastbootVars(string $raw): array
    {
        $vars = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $clean = trim((string)preg_replace('/^\(bootloader\)\s*/i', '', $line));
            $pos = strpos($clean, ':');
            if ($pos !== false && $pos > 0) {
                $k = strtolower(trim(substr($clean, 0, $pos)));
                $v = trim(substr($clean, $pos + 1));
                $vars[$k] = $v;
            }
        }
        return $vars;
    }
}
