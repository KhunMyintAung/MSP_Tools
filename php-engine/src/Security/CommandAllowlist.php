<?php
declare(strict_types=1);

namespace MspTools\Security;

final class CommandAllowlist
{
    private const ALLOWED_COMMANDS = [
        'adb_devices'      => ['adb', 'devices', '-l'],
        'adb_getprop'      => ['adb', 'shell', 'getprop'],
        'adb_partitions'   => ['adb', 'shell', 'cat', '/proc/partitions'],
        'fastboot_devices' => ['fastboot', 'devices'],
        'fastboot_getvar'  => ['fastboot', 'getvar', 'all'],
        'win_usb_ports'    => [
            'powershell',
            '-NoProfile',
            '-Command',
            'Get-PnpDevice -PresentOnly | Where-Object { $_.InstanceId -match "^USB" } | Select-Object FriendlyName, InstanceId, Status | ConvertTo-Json -Compress'
        ],
    ];

    private static function resolveBinary(string $bin): string
    {
        if ($bin === 'adb' || $bin === 'fastboot') {
            $local = __DIR__ . '/../../../bin/' . $bin . '.exe';
            if (is_file($local)) {
                return (string)realpath($local);
            }
        }
        return $bin;
    }

    public static function run(string $commandKey, int $timeoutSeconds = 6): array
    {
        if (!isset(self::ALLOWED_COMMANDS[$commandKey])) {
            return ['ok' => false, 'output' => '', 'stderr' => '', 'error' => "Command '{$commandKey}' not allowed."];
        }

        $cmdArray = self::ALLOWED_COMMANDS[$commandKey];
        $cmdArray[0] = self::resolveBinary($cmdArray[0]);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($cmdArray, $descriptors, $pipes, null, null, ['bypass_shell' => true]);

        if (!is_resource($process)) {
            return ['ok' => false, 'output' => '', 'stderr' => '', 'error' => "'{$cmdArray[0]}' not found in bin/ or PATH."];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $start = time();

        while (true) {
            $status = proc_get_status($process);
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            if (!$status['running']) {
                break;
            }
            if ((time() - $start) >= $timeoutSeconds) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                return [
                    'ok' => false,
                    'output' => trim($stdout),
                    'stderr' => trim($stderr),
                    'error' => "Timed out after {$timeoutSeconds}s."
                ];
            }
            usleep(30000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return [
            'ok'     => $code === 0,
            'output' => trim($stdout),
            'stderr' => trim($stderr),
            'error'  => $code === 0 ? null : (trim($stderr) ?: "{$cmdArray[0]} exited with code {$code}"),
        ];
    }
}
