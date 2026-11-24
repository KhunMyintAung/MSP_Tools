<?php
declare(strict_types=1);

namespace MspTools\Discovery;

use MspTools\Security\CommandAllowlist;

final class DeviceIdentityReader
{
    public function read(bool $includeUsbPorts = true): array
    {
        $adbList = CommandAllowlist::run('adb_devices', 3);
        $fbList  = CommandAllowlist::run('fastboot_devices', 3);

        $adbLines = array_values(array_filter(
            array_slice(preg_split('/\r\n|\r|\n/', $adbList['output'] ?? '') ?: [], 1),
            fn($l) => trim((string)$l) !== '' && !str_starts_with(trim((string)$l), '*')
        ));

        // 1) ADB family: device / recovery / sideload / unauthorized / offline
        if (!empty($adbLines)) {
            $parts = preg_split('/\s+/', trim($adbLines[0]));
            $serial = $parts[0] ?? 'unknown';
            $state  = strtolower($parts[1] ?? 'unknown');

            if ($state === 'device' || $state === 'recovery') {
                $propRes = CommandAllowlist::run('adb_getprop', 4);
                $p = $this->parseAdbProps($propRes['output'] ?? '');
                $unlocked = ($p['ro.boot.flash.locked'] ?? '') === '0'
                    || ($p['ro.boot.verifiedbootstate'] ?? '') === 'orange';

                $modeLabel = $state === 'recovery' ? 'ADB RECOVERY' : 'ADB';

                return [
                    'connected'      => true,
                    'mode'           => $modeLabel,
                    'transport'      => $modeLabel,
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
                    'comPort'        => 'ADB-USB',
                ];
            }

            if ($state === 'sideload') {
                return [
                    'connected'      => true,
                    'mode'           => 'ADB SIDELOAD',
                    'transport'      => 'ADB SIDELOAD',
                    'serial'         => $serial,
                    'brand'          => 'ANDROID',
                    'model'          => 'Recovery Sideload Mode',
                    'codename'       => '-',
                    'chipset'        => '-',
                    'androidVersion' => 'Sideload',
                    'securityPatch'  => '-',
                    'buildId'        => '-',
                    'slot'           => '-',
                    'bootloader'     => 'UNKNOWN',
                    'antiRollback'   => '-',
                    'comPort'        => 'ADB-USB',
                ];
            }

            // unauthorized / offline / other
            return [
                'connected'      => true,
                'mode'           => 'ADB',
                'transport'      => 'ADB (' . strtoupper($state) . ')',
                'serial'         => $serial,
                'brand'          => 'ANDROID',
                'model'          => $state === 'unauthorized'
                    ? 'Allow USB Debugging on phone screen'
                    : ('ADB state: ' . $state),
                'codename'       => '-',
                'chipset'        => '-',
                'androidVersion' => '-',
                'securityPatch'  => '-',
                'buildId'        => '-',
                'slot'           => '-',
                'bootloader'     => 'UNKNOWN',
                'antiRollback'   => '-',
                'comPort'        => 'ADB-USB',
            ];
        }

        // 2) Fastboot
        $fbLines = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $fbList['output'] ?? '') ?: [],
            fn($l) => trim((string)$l) !== ''
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
                'comPort'        => 'FASTBOOT-USB',
            ];
        }

        // 3) Hardware ports: EDL 9008 / BROM / Preloader / META / Odin / SPD
        if ($includeUsbPorts) {
            $usb = (new WindowsUsbScanner())->scan();
            if (!empty($usb['devices'])) {
                // Prefer service modes order
                $priority = [
                    'EDL 9008' => 0,
                    'QDLOADER 900E' => 1,
                    'MTK BROM' => 2,
                    'MTK META' => 3,
                    'MTK Preloader' => 4,
                    'MTK DA' => 5,
                    'ODIN DOWNLOAD' => 6,
                    'UNISOC SPD' => 7,
                    'FASTBOOT USB' => 8,
                ];
                usort($usb['devices'], function ($a, $b) use ($priority) {
                    $pa = $priority[$a['mode']] ?? 50;
                    $pb = $priority[$b['mode']] ?? 50;
                    return $pa <=> $pb;
                });

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
                    'comPort'        => $d['comPort'],
                ];
            }
        }

        return ['connected' => false, 'mode' => 'NONE'];
    }

    private function parseAdbProps(string $raw): array
    {
        $props = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            if (preg_match('/^\[([^\]]+)\]:\s*\[(.*)\]$/', trim($line), $m)) {
                $props[$m[1]] = $m[2];
            }
        }
        return $props;
    }

    private function parseFastbootVars(string $raw): array
    {
        $vars = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
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
