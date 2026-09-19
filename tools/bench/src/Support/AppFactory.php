<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Bench\Support;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;
use Sunchayn\Strata\StrataServiceProvider;

/**
 * Boots a real Laravel application wired with the strata driver and Laravel's own file driver.
 */
final class AppFactory
{
    public static function create(string $strataDirectory, string $fileCachePath): Application
    {
        $application = TestbenchApplication::create(
            options: ['extra' => ['providers' => [StrataServiceProvider::class]]],
        );

        $application->make('config')->set([
            'strata.directory' => $strataDirectory,
            'cache.stores.strata' => ['driver' => 'strata'],
            'cache.stores.laravelFileDriver' => ['driver' => 'file', 'path' => $fileCachePath],
        ]);

        return $application;
    }
}
