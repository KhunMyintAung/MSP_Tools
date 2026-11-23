<?php
declare(strict_types=1);

namespace MspTools\Discovery;

use MspTools\Security\CommandAllowlist;

final class WindowsUsbScanner
{
    private const KNOWN_MODES = [
        'VID_05C6&PID_9008' => ['chipset' => 'Qualcomm', 'mode' => 'EDL 9008',      'engine' => 'QualcommEngine'],
        'VID_0E8D&PID_0003' => ['chipset' => 'MediaTek', 'mode' => 'MTK BROM',      'engine' => 'MtkEngine'],
        'VID_0E8D&PID_2000' => ['chipset' => 'MediaTek', 'mode' => 'MTK Preloader', 'engine' => 'MtkEngine'],
        'VID_04E8&PID_685D' => ['chipset' => 'Samsung',  'mode' => 'ODIN DOWNLOAD', 'engine' => 'SamsungEngine'],
        'VID_1782&PID_4D00' => ['chipset' => 'Unisoc',   'mode' => 'UNISOC SPD',    'engine' => 'UnisocEngine'],
    ];

    public function scan(): array
    {
        $raw = CommandAllowlist::run('win_usb_ports', 5);
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
