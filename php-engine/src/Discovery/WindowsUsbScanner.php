<?php
declare(strict_types=1);

namespace MspTools\Discovery;

use MspTools\Security\CommandAllowlist;

final class WindowsUsbScanner
{
    /** VID/PID exact match (InstanceId contains) */
    private const KNOWN_MODES = [
        // Qualcomm EDL 9008 (+ common variants)
        'VID_05C6&PID_9008' => ['chipset' => 'Qualcomm', 'mode' => 'EDL 9008',       'engine' => 'QualcommEngine'],
        'VID_05C6&PID_900E' => ['chipset' => 'Qualcomm', 'mode' => 'QDLOADER 900E',  'engine' => 'QualcommEngine'],
        'VID_05C6&PID_901D' => ['chipset' => 'Qualcomm', 'mode' => 'QUALCOMM DIAG',  'engine' => 'QualcommEngine'],

        // MediaTek BROM / Preloader / META-ish ports
        'VID_0E8D&PID_0003' => ['chipset' => 'MediaTek', 'mode' => 'MTK BROM',       'engine' => 'MtkEngine'],
        'VID_0E8D&PID_2000' => ['chipset' => 'MediaTek', 'mode' => 'MTK Preloader',  'engine' => 'MtkEngine'],
        'VID_0E8D&PID_20FF' => ['chipset' => 'MediaTek', 'mode' => 'MTK META',       'engine' => 'MtkEngine'],
        'VID_0E8D&PID_2001' => ['chipset' => 'MediaTek', 'mode' => 'MTK META',       'engine' => 'MtkEngine'],

        // Samsung Download
        'VID_04E8&PID_685D' => ['chipset' => 'Samsung',  'mode' => 'ODIN DOWNLOAD',  'engine' => 'SamsungEngine'],
        'VID_04E8&PID_6860' => ['chipset' => 'Samsung',  'mode' => 'SAMSUNG ADB',    'engine' => 'SamsungEngine'],

        // Unisoc
        'VID_1782&PID_4D00' => ['chipset' => 'Unisoc',   'mode' => 'UNISOC SPD',     'engine' => 'UnisocEngine'],
    ];

    /** FriendlyName keyword fallback (when VID/PID not in list) */
    private const NAME_HINTS = [
        '9008'              => ['chipset' => 'Qualcomm', 'mode' => 'EDL 9008',      'engine' => 'QualcommEngine'],
        'QDLOADER'          => ['chipset' => 'Qualcomm', 'mode' => 'EDL 9008',      'engine' => 'QualcommEngine'],
        'QUALCOMM HS-USB'   => ['chipset' => 'Qualcomm', 'mode' => 'EDL 9008',      'engine' => 'QualcommEngine'],
        'ANDROID BOOTLOADER'=> ['chipset' => 'Android',  'mode' => 'FASTBOOT USB',  'engine' => 'FastbootEngine'],
        'FASTBOOT'          => ['chipset' => 'Android',  'mode' => 'FASTBOOT USB',  'engine' => 'FastbootEngine'],
        'MEDIATEK PRELOADER'=> ['chipset' => 'MediaTek', 'mode' => 'MTK Preloader', 'engine' => 'MtkEngine'],
        'PRELOADER USB VCOM'=> ['chipset' => 'MediaTek', 'mode' => 'MTK Preloader', 'engine' => 'MtkEngine'],
        'MTK USB PORT'      => ['chipset' => 'MediaTek', 'mode' => 'MTK META',      'engine' => 'MtkEngine'],
        'MEDIA TEK DA USB'  => ['chipset' => 'MediaTek', 'mode' => 'MTK DA',        'engine' => 'MtkEngine'],
        'META'              => ['chipset' => 'MediaTek', 'mode' => 'MTK META',      'engine' => 'MtkEngine'],
        'BROM'              => ['chipset' => 'MediaTek', 'mode' => 'MTK BROM',      'engine' => 'MtkEngine'],
        'DOWNLOAD MODE'     => ['chipset' => 'Samsung',  'mode' => 'ODIN DOWNLOAD', 'engine' => 'SamsungEngine'],
        'Gadget Serial'     => ['chipset' => 'Android',  'mode' => 'ADB GADGET',    'engine' => 'AdbEngine'],
    ];

    public function scan(): array
    {
        $raw = CommandAllowlist::run('win_usb_ports', 5);
        if (!$raw['ok'] || $raw['output'] === '') {
            return ['ok' => $raw['ok'], 'devices' => [], 'error' => $raw['error']];
        }

        $decoded = json_decode($raw['output'], true);
        if (!is_array($decoded)) {
            return ['ok' => true, 'devices' => [], 'error' => null];
        }
        if (isset($decoded['InstanceId'])) {
            $decoded = [$decoded];
        }

        $matched = [];
        $seen = [];

        foreach ($decoded as $item) {
            $instanceId = strtoupper((string)($item['InstanceId'] ?? ''));
            $friendlyName = (string)($item['FriendlyName'] ?? 'Unknown USB Device');
            $nameUpper = strtoupper($friendlyName);
            $comPort = 'USB-RAW';
            if (preg_match('/\((COM\d+)\)/i', $friendlyName, $m)) {
                $comPort = strtoupper($m[1]);
            }

            $meta = null;

            foreach (self::KNOWN_MODES as $vidPid => $info) {
                if (str_contains($instanceId, $vidPid)) {
                    $meta = $info;
                    break;
                }
            }

            if ($meta === null) {
                foreach (self::NAME_HINTS as $hint => $info) {
                    if (str_contains($nameUpper, strtoupper($hint))) {
                        $meta = $info;
                        break;
                    }
                }
            }

            if ($meta === null) {
                continue;
            }

            $key = $instanceId !== '' ? $instanceId : ($friendlyName . '|' . $comPort);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $matched[] = [
                'name'       => $friendlyName,
                'comPort'    => $comPort,
                'instanceId' => $instanceId,
                'status'     => $item['Status'] ?? 'OK',
                'chipset'    => $meta['chipset'],
                'mode'       => $meta['mode'],
                'engine'     => $meta['engine'],
            ];
        }

        return ['ok' => true, 'devices' => $matched, 'error' => null];
    }
}
