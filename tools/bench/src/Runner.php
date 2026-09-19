<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Bench;

use Sunchayn\Strata\Bench\Contracts\Renderer;
use Sunchayn\Strata\Bench\Contracts\Scenario;
use Sunchayn\Strata\Bench\Support\Environment;
use Sunchayn\Strata\Bench\Support\Verdict;
use Sunchayn\Strata\Bench\ValueObjects\AggregatedRow;
use Sunchayn\Strata\Bench\ValueObjects\Row;
use Sunchayn\Strata\Bench\ValueObjects\RowKind;

/**
 * Runs every scenario for its own declared rep count, averages same-position rows across those reps,
 * then renders each scenario's table and a closing summary, through the given Renderer.
 */
final class Runner
{
    /**
     * @param  array<int, Scenario>  $scenarios
     */
    public function __construct(
        private readonly array $scenarios,
        private readonly Renderer $renderer,
    ) {}

    public function run(Environment $environment): void
    {
        $summaries = [];

        foreach ($this->scenarios as $scenario) {
            $this->renderer->heading($scenario->name());

            $reps = $scenario->reps();

            $aggregated = $this->aggregate($this->repeat($scenario, $environment, $reps), $reps);

            $this->renderer->table(['condition', 'strata', 'laravelFileDriver', 'verdict'], array_map(
                fn (AggregatedRow $row): array => $this->toTableRow($row, $reps),
                $aggregated,
            ));

            $summaries[$scenario->name()] = $scenario->summary($aggregated, $reps);
        }

        $this->renderer->heading('Summary');

        foreach ($summaries as $name => $summary) {
            $this->renderer->text("{$name}: {$summary}");
        }
    }

    /**
     * @return array<int, array<int, Row>> One array of rows per rep.
     */
    private function repeat(Scenario $scenario, Environment $environment, int $reps): array
    {
        return array_map(fn (): array => $scenario->run($environment), range(1, $reps));
    }

    /**
     * Average every rep's row at the same position, position by position,
     * since a scenario returns its rows in the same order on every call.
     *
     * @param  array<int, array<int, Row>>  $reps
     * @return array<int, AggregatedRow>
     */
    private function aggregate(array $reps, int $repCount): array
    {
        // A single-rep scenario carries its own aggregate already, so it is used as is.
        if ($repCount === 1) {
            return array_map($this->toAggregatedRow(...), $reps[0]);
        }

        $aggregated = [];

        foreach ($reps[0] as $position => $firstRepRow) {
            $strataValues = array_map(fn (array $rep): float => $rep[$position]->strata, $reps);
            $laravelFileDriverValues = array_map(fn (array $rep): float => $rep[$position]->laravelFileDriver, $reps);

            $isDuration = $firstRepRow->kind === RowKind::Duration;

            $aggregated[] = new AggregatedRow(
                label: $firstRepRow->label,
                kind: $firstRepRow->kind,
                strata: array_sum($strataValues) / count($strataValues),
                laravelFileDriver: array_sum($laravelFileDriverValues) / count($laravelFileDriverValues),
                strataVariancePercent: $isDuration ? $this->relativeStdDevPercent($strataValues) : null,
                laravelFileDriverVariancePercent: $isDuration ? $this->relativeStdDevPercent($laravelFileDriverValues) : null,
            );
        }

        return $aggregated;
    }

    private function toAggregatedRow(Row $row): AggregatedRow
    {
        return new AggregatedRow(
            label: $row->label,
            kind: $row->kind,
            strata: $row->strata,
            laravelFileDriver: $row->laravelFileDriver,
            strataVariancePercent: $row->strataVariancePercent,
            laravelFileDriverVariancePercent: $row->laravelFileDriverVariancePercent,
        );
    }

    /**
     * @param  array<int, float>  $samples
     */
    private function relativeStdDevPercent(array $samples): float
    {
        $mean = array_sum($samples) / count($samples);

        if ($mean <= 0.0) {
            return 0.0;
        }

        $variance = array_sum(array_map(fn (float $sample): float => ($sample - $mean) ** 2, $samples)) / count($samples);

        return sqrt($variance) / $mean * 100;
    }

    /**
     * @return array<int, string>
     */
    private function toTableRow(AggregatedRow $row, int $reps): array
    {
        return [
            $row->label,
            $this->formatValue($row, $row->strata, $row->strataVariancePercent, $reps),
            $this->formatValue($row, $row->laravelFileDriver, $row->laravelFileDriverVariancePercent, $reps),
            $this->verdict($row, $reps),
        ];
    }

    private function formatValue(AggregatedRow $row, float $mean, ?float $variancePercent, int $reps): string
    {
        return match ($row->kind) {
            RowKind::Duration => sprintf('%s ms (±%d%%)', number_format($mean, 3), round($variancePercent ?? 0.0)),
            RowKind::Count => rtrim(rtrim(number_format($mean, 2), '0'), '.'),
            RowKind::Invariant => $this->invariantLabel($mean, $reps),
        };
    }

    /**
     * A held rep counts as 1.0 in its mean.
     * Multiplying the mean back by the rep count recovers how many reps actually held the invariant.
     */
    private function invariantLabel(float $mean, int $reps): string
    {
        $held = $this->heldCount($mean, $reps);

        return $held === $reps ? "held {$held}/{$reps} reps" : "VIOLATED {$held}/{$reps} reps";
    }

    private function verdict(AggregatedRow $row, int $reps): string
    {
        return match ($row->kind) {
            RowKind::Duration => Verdict::speed($row->strata, $row->laravelFileDriver),
            RowKind::Count => $this->countVerdict($row),
            RowKind::Invariant => $this->invariantVerdict($row, $reps),
        };
    }

    private function countVerdict(AggregatedRow $row): string
    {
        $strata = (int) round($row->strata);
        $laravelFileDriver = (int) round($row->laravelFileDriver);

        return $strata === $laravelFileDriver ? 'match' : "differs (strata: {$strata}, laravelFileDriver: {$laravelFileDriver})";
    }

    private function invariantVerdict(AggregatedRow $row, int $reps): string
    {
        $strataHeld = $this->heldCount($row->strata, $reps) === $reps;
        $laravelFileDriverHeld = $this->heldCount($row->laravelFileDriver, $reps) === $reps;

        return match (true) {
            $strataHeld && $laravelFileDriverHeld => 'held (both)',
            ! $strataHeld && ! $laravelFileDriverHeld => 'VIOLATED (both)',
            ! $strataHeld => 'VIOLATED (strata)',
            default => 'VIOLATED (laravelFileDriver)',
        };
    }

    private function heldCount(float $mean, int $reps): int
    {
        return (int) round($mean * $reps);
    }
}
