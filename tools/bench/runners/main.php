<?php

declare(strict_types=1);

require __DIR__.'/../../../vendor/autoload.php';

use Sunchayn\Strata\Bench\Renderers\ConsoleRenderer;
use Sunchayn\Strata\Bench\Renderers\MarkdownRenderer;
use Sunchayn\Strata\Bench\Runner;
use Sunchayn\Strata\Bench\Scenarios\Benchmarks\DataShapeBench;
use Sunchayn\Strata\Bench\Scenarios\Benchmarks\EvictionReadCostBench;
use Sunchayn\Strata\Bench\Scenarios\Benchmarks\PhpBenchScenarioWrapper;
use Sunchayn\Strata\Bench\Scenarios\Benchmarks\ThroughputBench;
use Sunchayn\Strata\Bench\Support\Environment;
use Sunchayn\Strata\Bench\Support\MachineInfo;

/**
 * The phpbench-driven timing becnhmarks,
 * rendered through the same comparison table every scenario uses instead phpbench's own report.
 *
 * @see runners/concurrency.php for the multi-process checks, which stay outside phpbench.
 */
$renderer = getenv('BENCH_FORMAT') === 'markdown' ? new MarkdownRenderer : new ConsoleRenderer;

$showSpinner = $renderer instanceof ConsoleRenderer;

$environment = Environment::shared();

$runner = new Runner(
    scenarios: [
        new PhpBenchScenarioWrapper(ThroughputBench::class, 'Read/write throughput', $showSpinner),
        new PhpBenchScenarioWrapper(DataShapeBench::class, 'Data shape coverage', $showSpinner),
        new PhpBenchScenarioWrapper(EvictionReadCostBench::class, 'Expiry/eviction read cost vs payload size', $showSpinner),
    ],
    renderer: $renderer,
);

$renderer->heading('Strata vs stock Laravel cache benchmark');

$machine = MachineInfo::describe();

$renderer->table(['machine', 'value'], array_map(
    fn (string $key, string $value): array => [$key, $value],
    array_keys($machine),
    array_values($machine),
));

$runner->run($environment);
