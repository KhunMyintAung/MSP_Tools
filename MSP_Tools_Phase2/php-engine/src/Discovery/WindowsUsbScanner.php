<?php
declare(strict_types=1);

namespace MspTools\Discovery;

use MspTools\Security\CommandAllowlist;

final class WindowsUsbScanner
{
    private const KNOWN_MODES = [
        'VID_05C6&PID_9008' => ['chipset' => 'Qualcomm', 'mode' => 'EDL 9008 Mode',         'engine' => 'QualcommEngine'],
        'VID_0E8D&PID_0003' => ['chipset' => 'MediaTek', 'mode' => 'BROM Mode',             'engine' => 'MtkEngine'],
        'VID_0E8D&PID_2000' => ['chipset' => 'MediaTek', 'mode' => 'Preloader Mode',        'engine' => 'MtkEngine'],
        'VID_04E8&PID_685D' => ['chipset' => 'Samsung',  'mode' => 'Odin / Download Mode',  'engine' => 'SamsungEngine'],
        'VID_1782&PID_4D00' => ['chipset' => 'Unisoc',   'mode' => 'SPRD Download Mode',    'engine' => 'UnisocEngine'],
        'VID_18D1&PID_D00D' => ['chipset' => 'Android',  'mode' => 'Fastboot / Bootloader', 'engine' => 'AndroidStandardEngine'],
    ];

    public function scan(): array
    {
        $raw = CommandAllowlist::run('win_usb_ports');
        if (!$raw['ok'] || $raw['output'] === '') {
            return ['ok' => $raw['ok'], 'devices' => [], 'error' => $raw['error']];
        }

        $decoded = json_decode($raw['output'], true);
        if (!is_array($decoded)) return ['ok' => true, 'devices' => [], 'error' => null];
        if (isset($decoded['InstanceId'])) $decoded = [$decoded];

        $matched = [];
        foreach ($decoded as $item) {
            $instanceId = strtoupper((string)($item['InstanceId'] ?? ''));
            $friendlyName = (string)($item['FriendlyName'] ?? 'Unknown USB Device');
            $comPort = 'USB-RAW';
            if (preg_match('/\((COM\d+)\)/i', $friendlyName, $m)) {
                $comPort = strtoupper($m[1]);
            }

            foreach (self::KNOWN_MODES as $vidPid => $meta) {
                if (str_contains($instanceId, $vidPid)) {
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
            }
        }
        return ['ok' => true, 'devices' => $matched, 'error' => null];
    }
}
