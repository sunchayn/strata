<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Bench\Support;

use Exception;
use Sunchayn\Strata\Bench\Contracts\Scenario;
use Sunchayn\Strata\Bench\ValueObjects\AggregatedRow;
use Sunchayn\Strata\Bench\ValueObjects\Row;
use Sunchayn\Strata\Bench\ValueObjects\RowKind;

use function Laravel\Prompts\spin;

/**
 * Adapts one PHPBench benchmark class into a Scenario,
 * so the Runner treats it the same as the in-process concurrency scenarios.
 * PHPBench already repeats and aggregates internally, so this always reports reps() as 1,
 * carrying phpbench's own variance straight through instead of it being averaged a second time.
 */
final class PhpBenchScenarioWrapper implements Scenario
{
    public function __construct(
        private readonly string $benchmarkClass,
        private readonly string $heading,
        private readonly bool $showSpinner,
    ) {}

    public function name(): string
    {
        return $this->heading;
    }

    public function reps(): int
    {
        return 1;
    }

    /**
     * @return array<int, Row>
     *
     * @throws Exception
     */
    public function run(Environment $environment): array
    {
        $dumpFilePath = $this->showSpinner
            ? spin(fn (): string => PhpBenchProcess::run($this->benchmarkClass), "Running {$this->heading}…")
            : PhpBenchProcess::run($this->benchmarkClass);

        try {
            $grouped = PhpBenchReport::parse($dumpFilePath);
        } finally {
            unlink($dumpFilePath);
        }

        // Every dump holds exactly one benchmark class, filtered above, so exactly one group comes back.
        return array_map($this->toRow(...), reset($grouped) ?: []);
    }

    /**
     * @param  array<int, AggregatedRow>  $rows
     */
    public function summary(array $rows, int $reps): string
    {
        return Verdict::summarize($rows);
    }

    private function toRow(AggregatedRow $row): Row
    {
        return new Row(
            label: $row->label,
            kind: RowKind::Duration,
            strata: $row->strata,
            laravelFileDriver: $row->laravelFileDriver,
            strataVariancePercent: $row->strataVariancePercent,
            laravelFileDriverVariancePercent: $row->laravelFileDriverVariancePercent,
        );
    }
}
