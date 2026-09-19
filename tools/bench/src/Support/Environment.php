<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Bench\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;

/**
 * The shared app and directories every scenario runs against.
 * Also spawns worker subprocesses against those same directories, for the concurrency scenarios.
 */
final class Environment
{
    private static ?self $shared = null;

    public function __construct(
        public readonly Application $app,
        public readonly string $root,
        public readonly string $strataDirectory,
        public readonly string $fileCachePath,
    ) {}

    /**
     * One environment for the whole phpbench run, built the first time any benchmark class asks for it.
     * PHPBench's local executor runs every iteration in this same process, so this is safe to memoize.
     */
    public static function shared(): self
    {
        return self::$shared ??= self::boot();
    }

    public function store(string $driver): Repository
    {
        return $this->app->make('cache')->store($driver);
    }

    /**
     * Launches every worker at roughly the same time, then collects their outputs.
     * proc_open returns as soon as a process is forked, so starting every worker first keeps them concurrent.
     *
     * @param  array<int, array<int, string>>  $argsList  One argv list per worker.
     * @return array<int, array<string, mixed>|null> One parsed result per worker, same order as $argsList.
     */
    public function spawnConcurrently(array $argsList): array
    {
        $script = __DIR__.'/../../worker.php';

        $handles = [];

        foreach ($argsList as $index => $args) {
            $command = array_merge([PHP_BINARY, $script], $args);

            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

            if (is_resource($process)) {
                $handles[$index] = ['process' => $process, 'pipes' => $pipes];
            }
        }

        $results = [];

        foreach ($handles as $index => $handle) {
            $output = stream_get_contents($handle['pipes'][1]);

            fclose($handle['pipes'][1]);
            fclose($handle['pipes'][2]);

            proc_close($handle['process']);

            $decoded = json_decode(trim((string) $output), associative: true);

            $results[$index] = is_array($decoded) ? $decoded : null;
        }

        return $results;
    }

    private static function boot(): self
    {
        $root = sys_get_temp_dir().'/strata-bench-'.Str::random(8);
        $strataDirectory = $root.'/strata';
        $fileCachePath = $root.'/file';

        $filesystem = new Filesystem;
        $filesystem->ensureDirectoryExists($strataDirectory);
        $filesystem->ensureDirectoryExists($fileCachePath);

        register_shutdown_function(static fn () => $filesystem->deleteDirectory($root));

        return new self(
            app: AppFactory::create($strataDirectory, $fileCachePath),
            root: $root,
            strataDirectory: $strataDirectory,
            fileCachePath: $fileCachePath,
        );
    }
}
