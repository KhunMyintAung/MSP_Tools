<?php
declare(strict_types=1);

namespace MspTools\Security;

final class CommandAllowlist
{
    private const ALLOWED_COMMANDS = [
        'adb_devices'      => ['adb', 'devices', '-l'],
        'fastboot_devices' => ['fastboot', 'devices'],
        'adb_getprop'      => ['adb', 'shell', 'getprop'],
        'fastboot_getvar'  => ['fastboot', 'getvar', 'all'],
        'win_usb_ports'    => [
            'powershell',
            '-NoProfile',
            '-Command',
            'Get-PnpDevice -PresentOnly | Where-Object { $_.InstanceId -match "^USB" } | Select-Object FriendlyName, InstanceId, Status | ConvertTo-Json -Compress'
        ],
    ];

    public static function run(string $commandKey, int $timeoutSeconds = 8): array
    {
        if (!isset(self::ALLOWED_COMMANDS[$commandKey])) {
            return [
                'ok'     => false,
                'output' => '',
                'error'  => "Command '{$commandKey}' is not in the security allowlist."
            ];
        }

        $cmdArray = self::ALLOWED_COMMANDS[$commandKey];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($cmdArray, $descriptors, $pipes, null, null, ['bypass_shell' => true]);

        if (!is_resource($process)) {
            return [
                'ok'     => false,
                'output' => '',
                'error'  => "Unable to execute '{$cmdArray[0]}'. Verify it is installed and available in system PATH."
            ];
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
                    'ok'     => false,
                    'output' => trim($stdout),
                    'error'  => "Command '{$commandKey}' timed out after {$timeoutSeconds}s."
                ];
            }

            usleep(40000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'ok'     => $exitCode === 0,
            'output' => trim($stdout),
            'error'  => $exitCode === 0 ? null : (trim($stderr) ?: "{$cmdArray[0]} exited with code {$exitCode}")
        ];
    }
}
