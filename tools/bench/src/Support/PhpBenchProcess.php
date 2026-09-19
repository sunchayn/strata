<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Bench\Support;

use RuntimeException;

/**
 * Shells out to phpbench for a single benchmark class, dumping its raw XML result to a temp file.
 */
final class PhpBenchProcess
{
    /**
     * @throws RuntimeException When phpbench exits with a failure, or never wrote the dump file.
     */
    public static function run(string $benchmarkClass): string
    {
        $configPath = __DIR__.'/../../phpbench.json';
        $dumpFilePath = tempnam(sys_get_temp_dir(), 'strata-bench-').'.xml';

        // --filter matches as a regex, and the backslashes in a fully-qualified class name break that.
        $shortName = substr((string) strrchr($benchmarkClass, '\\'), 1);

        $command = [
            PHP_BINARY,
            'vendor/bin/phpbench',
            'run',
            '--config='.$configPath,
            '--filter='.$shortName,
            '--progress=none',
            '--quiet',
            '--dump-file='.$dumpFilePath,
        ];

        exec(implode(' ', array_map('escapeshellarg', $command)), result_code: $exitCode);

        if ($exitCode !== 0 || ! file_exists($dumpFilePath)) {
            throw new RuntimeException("phpbench run failed for \"{$benchmarkClass}\", see its output above.");
        }

        return $dumpFilePath;
    }
}
