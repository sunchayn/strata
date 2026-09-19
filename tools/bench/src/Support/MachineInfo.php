<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Bench\Support;

use Composer\InstalledVersions;
use Throwable;

/**
 * Machine details for the run.
 */
final class MachineInfo
{
    /**
     * @return array<string, string>
     */
    public static function describe(): array
    {
        $opcache = function_exists('opcache_get_status') && (opcache_get_status(false)['opcache_enabled'] ?? false);

        return [
            'OS' => php_uname('s').' '.php_uname('r').' ('.php_uname('m').')',
            'PHP' => PHP_VERSION.' (opcache '.($opcache ? 'on' : 'off').')',
            'CPU cores' => self::cpuCores(),
            'Memory' => self::totalMemoryGb(),
            'illuminate/support' => self::illuminateVersion(),
        ];
    }

    private static function illuminateVersion(): string
    {
        return InstalledVersions::getPrettyVersion('illuminate/support')
            ?? InstalledVersions::getPrettyVersion('laravel/framework')
            ?? 'unknown';
    }

    private static function cpuCores(): string
    {
        $command = match (PHP_OS_FAMILY) {
            'Darwin' => 'sysctl -n hw.ncpu',
            'Linux' => 'nproc',
            default => null,
        };

        $cores = $command !== null ? self::shellNumber($command) : null;

        return $cores !== null ? (string) (int) $cores : self::linuxCpuCoresFromCpuInfo() ?? 'unknown';
    }

    /**
     * Falls back to counting processor lines when nproc is unavailable on Linux.
     */
    private static function linuxCpuCoresFromCpuInfo(): ?string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return null;
        }

        $cpuinfo = @file_get_contents('/proc/cpuinfo');

        return $cpuinfo === false ? null : (string) substr_count($cpuinfo, "\nprocessor\t:");
    }

    private static function totalMemoryGb(): string
    {
        $bytes = match (PHP_OS_FAMILY) {
            'Darwin' => self::shellNumber('sysctl -n hw.memsize'),
            'Linux' => self::linuxMemTotalBytes(),
            default => null,
        };

        return $bytes !== null ? number_format($bytes / (1024 ** 3), 1).' GB' : 'unknown';
    }

    private static function linuxMemTotalBytes(): ?float
    {
        $meminfo = @file_get_contents('/proc/meminfo');

        if ($meminfo === false || ! preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $matches)) {
            return null;
        }

        return ((float) $matches[1]) * 1024;
    }

    /**
     * Runs a shell command and returns its output as a number, or null when it fails.
     */
    private static function shellNumber(string $command): ?float
    {
        if (! function_exists('shell_exec')) {
            return null;
        }

        try {
            $output = shell_exec($command);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($output)) {
            return null;
        }

        $trimmed = trim($output);

        return is_numeric($trimmed) ? (float) $trimmed : null;
    }
}
