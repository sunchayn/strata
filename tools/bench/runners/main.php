<?php

declare(strict_types=1);

require __DIR__.'/../../../vendor/autoload.php';

use Sunchayn\Strata\Bench\Renderers\ConsoleRenderer;
use Sunchayn\Strata\Bench\Renderers\MarkdownRenderer;
use Sunchayn\Strata\Bench\Runner;
use Sunchayn\Strata\Bench\Scenarios\Benchmarks\DataShapeBench;
use Sunchayn\Strata\Bench\Scenarios\Benchmarks\EvictionReadCostBench;
use Sunchayn\Strata\Bench\Scenarios\Benchmarks\TagBench;
use Sunchayn\Strata\Bench\Scenarios\Benchmarks\TagMissBench;
use Sunchayn\Strata\Bench\Scenarios\Benchmarks\ThroughputBench;
use Sunchayn\Strata\Bench\Support\Environment;
use Sunchayn\Strata\Bench\Support\MachineInfo;
use Sunchayn\Strata\Bench\Support\PhpBenchProcess;
use Sunchayn\Strata\Bench\Support\PhpBenchScenarioWrapper;
use Sunchayn\Strata\Bench\Support\TagBenchReport;

use function Laravel\Prompts\spin;

/**
 * The phpbench-driven timing becnhmarks,
 * rendered through the same comparison table every scenario uses instead phpbench's own report.
 *
 * @see runners/concurrency.php for the multi-process checks, which stay outside phpbench.
 */
$renderer = getenv('BENCH_FORMAT') === 'markdown' ? new MarkdownRenderer : new ConsoleRenderer;

$showSpinner = $renderer instanceof ConsoleRenderer;

$environment = Environment::singleton();

$runner = new Runner(
    scenarios: [
        new PhpBenchScenarioWrapper(ThroughputBench::class, 'Read/write throughput', $showSpinner),
        new PhpBenchScenarioWrapper(DataShapeBench::class, 'Data shape coverage', $showSpinner),
        new PhpBenchScenarioWrapper(EvictionReadCostBench::class, 'Expiry/eviction read cost vs payload size', $showSpinner),
    ],
    renderer: $renderer,
);

$renderer->heading('Strata vs Default Laravel File Cache Benchmarking');

$machine = MachineInfo::describe();

$renderer->table(
    ['machine', 'value'],
    array_map(
        fn (string $key, string $value): array => [$key, $value],
        array_keys($machine),
        array_values($machine),
    ),
);

$renderer->heading('1:1 Benchmarking');

$runner->run($environment);

$renderer->heading('Strata Tagging Benchmarking');
$renderer->text('Benchmark tagging for Strata for put operations without (baseline) and with tagging.');

$tagDumpFilePath = $showSpinner
    ? spin(fn (): string => PhpBenchProcess::run(TagBench::class), 'Running Strata tagging benchmark…')
    : PhpBenchProcess::run(TagBench::class);

try {
    $tagRows = TagBenchReport::parse($tagDumpFilePath);
} finally {
    unlink($tagDumpFilePath);
}

$tagMissDumpFilePath = $showSpinner
    ? spin(fn (): string => PhpBenchProcess::run(TagMissBench::class), 'Running Strata tag-miss benchmark…')
    : PhpBenchProcess::run(TagMissBench::class);

try {
    $tagMissRows = TagBenchReport::parse($tagMissDumpFilePath);
} finally {
    unlink($tagMissDumpFilePath);
}

$renderer->table(['condition', 'mean ms', 'vs baseline'], [...$tagRows, ...$tagMissRows]);
