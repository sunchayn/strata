<?php

declare(strict_types=1);

require __DIR__.'/../../../vendor/autoload.php';

use Sunchayn\Strata\Bench\Renderers\ConsoleRenderer;
use Sunchayn\Strata\Bench\Renderers\MarkdownRenderer;
use Sunchayn\Strata\Bench\Runner;
use Sunchayn\Strata\Bench\Scenarios\AddAtomicityScenario;
use Sunchayn\Strata\Bench\Scenarios\LockContentionScenario;
use Sunchayn\Strata\Bench\Support\Environment;
use Sunchayn\Strata\Bench\Support\MachineInfo;

/**
 * Run multi-process concurrency checks for add() atomicity and lock contention.
 * These spawn actual OS processes racing each other, something phpbench's doesn't support.
 *
 * @see runners/main.php for the phpbench-driven timing benchmarks.
 */
$reps = max(1, (int) (getenv('BENCH_REPS') ?: 5));

$renderer = getenv('BENCH_FORMAT') === 'markdown' ? new MarkdownRenderer : new ConsoleRenderer;

$environment = Environment::shared();

$runner = new Runner(
    scenarios: [
        new AddAtomicityScenario($reps),
        new LockContentionScenario($reps),
    ],
    renderer: $renderer,
);

$renderer->heading('Strata vs stock Laravel cache: concurrency checks');
$renderer->text("Cache root: {$environment->root}");
$renderer->text("Averaging every scenario over {$reps} reps. Override with BENCH_REPS=N. Output format: BENCH_FORMAT=terminal|markdown.");

$machine = MachineInfo::describe();

$renderer->table(['machine', 'value'], array_map(
    fn (string $key, string $value): array => [$key, $value],
    array_keys($machine),
    array_values($machine),
));

$runner->run($environment);
