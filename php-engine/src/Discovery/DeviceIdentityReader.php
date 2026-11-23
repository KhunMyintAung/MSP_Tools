<?php
declare(strict_types=1);

namespace MspTools\Discovery;

use MspTools\Security\CommandAllowlist;

final class DeviceIdentityReader
{
    public function read(bool $includeUsbPorts = true): array
    {
        $adbList = CommandAllowlist::run('adb_devices', 3);
        $fbList = CommandAllowlist::run('fastboot_devices', 3);

        $adbLines = array_values(array_filter(
            array_slice(preg_split('/\r\n|\r|\n/', $adbList['output'] ?? ''), 1),
            fn($l) => trim($l) !== '' && !str_starts_with(trim($l), '*')
        ));

        // 1. ADB Mode
        if (!empty($adbLines)) {
            $parts = preg_split('/\s+/', trim($adbLines[0]));
            $serial = $parts[0] ?? 'unknown';
            $state = $parts[1] ?? 'unknown';

            if ($state === 'device') {
                $propRes = CommandAllowlist::run('adb_getprop', 4);
                $p = $this->parseAdbProps($propRes['output'] ?? '');
                $unlocked = ($p['ro.boot.flash.locked'] ?? '') === '0' || ($p['ro.boot.verifiedbootstate'] ?? '') === 'orange';

                return [
                    'connected'      => true,
                    'mode'           => 'ADB',
                    'transport'      => 'ADB',
                    'serial'         => $serial,
                    'brand'          => strtoupper($p['ro.product.brand'] ?? $p['ro.product.manufacturer'] ?? 'ANDROID'),
                    'model'          => $p['ro.product.marketname'] ?? $p['ro.product.model'] ?? 'Android Device',
                    'codename'       => $p['ro.product.device'] ?? $p['ro.build.product'] ?? '-',
                    'chipset'        => $p['ro.board.platform'] ?? $p['ro.hardware'] ?? $p['ro.soc.model'] ?? 'Unknown SoC',
                    'androidVersion' => $p['ro.build.version.release'] ?? '-',
                    'securityPatch'  => $p['ro.build.version.security_patch'] ?? '-',
                    'buildId'        => $p['ro.build.display.id'] ?? $p['ro.build.id'] ?? '-',
                    'slot'           => $p['ro.boot.slot_suffix'] ?? 'non-AB',
                    'bootloader'     => $unlocked ? 'UNLOCKED' : 'LOCKED',
                    'antiRollback'   => '-',
                    'comPort'        => 'ADB-USB'
                ];
            }

            return [
                'connected'      => true,
                'mode'           => 'ADB',
                'transport'      => 'ADB (' . strtoupper($state) . ')',
                'serial'         => $serial,
                'brand'          => 'ANDROID',
                'model'          => 'Allow USB Debugging on phone screen',
                'codename'       => '-',
                'chipset'        => '-',
                'androidVersion' => '-',
                'securityPatch'  => '-',
                'buildId'        => '-',
                'slot'           => '-',
                'bootloader'     => 'UNKNOWN',
                'antiRollback'   => '-',
                'comPort'        => 'ADB-USB'
            ];
        }

        // 2. Fastboot Mode
        $fbLines = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $fbList['output'] ?? ''),
            fn($l) => trim($l) !== ''
        ));

        if (!empty($fbLines)) {
            $serial = preg_split('/\s+/', trim($fbLines[0]))[0] ?? 'unknown';
            $varRes = CommandAllowlist::run('fastboot_getvar', 3);
            $v = $this->parseFastbootVars(($varRes['stderr'] ?? '') . "\n" . ($varRes['output'] ?? ''));
            $product = $v['product'] ?? $v['variant'] ?? "Fastboot Device ({$serial})";

            return [
                'connected'      => true,
                'mode'           => 'FASTBOOT',
                'transport'      => 'FASTBOOT',
                'serial'         => $serial,
                'brand'          => strtoupper($v['manufacturer'] ?? 'FASTBOOT'),
                'model'          => $product,
                'codename'       => $product,
                'chipset'        => $v['platform'] ?? $v['hw-revision'] ?? 'Bootloader SoC',
                'androidVersion' => 'Fastboot Mode',
                'securityPatch'  => '-',
                'buildId'        => $v['version-bootloader'] ?? '-',
                'slot'           => isset($v['current-slot']) ? '_' . $v['current-slot'] : 'non-AB',
                'bootloader'     => ($v['unlocked'] ?? '') === 'yes' ? 'UNLOCKED' : (($v['unlocked'] ?? '') === 'no' ? 'LOCKED' : 'UNKNOWN'),
                'antiRollback'   => $v['anti'] ?? '-',
                'comPort'        => 'FASTBOOT-USB'
            ];
        }

        // 3. Hardware Service Port
        if ($includeUsbPorts) {
            $usb = (new WindowsUsbScanner())->scan();
            if (!empty($usb['devices'])) {
                $d = $usb['devices'][0];
                return [
                    'connected'      => true,
                    'mode'           => $d['mode'],
                    'transport'      => $d['mode'],
                    'serial'         => $d['instanceId'],
                    'brand'          => strtoupper($d['chipset']),
                    'model'          => $d['name'],
                    'codename'       => $d['engine'],
                    'chipset'        => $d['chipset'],
                    'androidVersion' => 'Hardware Port',
                    'securityPatch'  => '-',
                    'buildId'        => $d['status'],
                    'slot'           => '-',
                    'bootloader'     => 'HARDWARE-LEVEL',
                    'antiRollback'   => '-',
                    'comPort'        => $d['comPort']
                ];
            }
        }

        return ['connected' => false, 'mode' => 'NONE'];
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

