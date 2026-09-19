<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use Sunchayn\Strata\Bench\Scenarios\AddAtomicityScenario;
use Sunchayn\Strata\Bench\Scenarios\LockContentionScenario;
use Sunchayn\Strata\Bench\Support\AppFactory;

/**
 * Child process entrypoint for the concurrency scenarios.
 * Performs one add() or one lock acquire, hold, and release against a cache directory shared with its siblings.
 * Prints a single JSON line so the parent process can collect the outcome.
 *
 * @see AddAtomicityScenario
 * @see LockContentionScenario
 */
[, $mode, $driver, $strataDirectory, $fileCachePath, $subject] = $argv;

$app = AppFactory::create($strataDirectory, $fileCachePath);

$store = $app->make('cache')->store($driver);

if ($mode === 'add') {
    echo json_encode(['success' => $store->add($subject, getmypid(), 60)]).PHP_EOL;

    exit(0);
}

if ($mode === 'lock') {
    $holdMs = (int) ($argv[6] ?? 20);
    $lock = $store->lock($subject, 5);

    $acquired = false;
    $deadline = microtime(true) + 2.0;

    while (microtime(true) < $deadline) {
        if ($lock->get()) {
            $acquired = true;

            break;
        }

        usleep(2_000);
    }

    if (! $acquired) {
        echo json_encode(['acquired' => false]).PHP_EOL;

        exit(0);
    }

    $start = microtime(true);

    usleep($holdMs * 1_000);

    $end = microtime(true);

    $lock->release();

    echo json_encode(['acquired' => true, 'start' => $start, 'end' => $end]).PHP_EOL;

    exit(0);
}

fwrite(STDERR, "Unknown worker mode {$mode}".PHP_EOL);

exit(1);
