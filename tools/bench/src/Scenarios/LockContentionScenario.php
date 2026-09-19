<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Bench\Scenarios;

use Illuminate\Support\Str;
use RuntimeException;
use Sunchayn\Strata\Bench\Contracts\Scenario;
use Sunchayn\Strata\Bench\Support\Environment;
use Sunchayn\Strata\Bench\ValueObjects\AggregatedRow;
use Sunchayn\Strata\Bench\ValueObjects\Row;
use Sunchayn\Strata\Bench\ValueObjects\RowKind;

/**
 * Several real processes race to acquire the same named lock.
 * Only one should ever hold it at a time, checked by comparing each worker's recorded hold window for overlaps.
 */
final class LockContentionScenario implements Scenario
{
    private const int WORKERS = 16;

    private const int HOLD_MS = 20;

    private const string HOLDS_LABEL = 'held the lock out of '.self::WORKERS;

    public function __construct(private readonly int $reps = 5) {}

    public function name(): string
    {
        return sprintf('Lock contention under %d concurrent processes', self::WORKERS);
    }

    public function reps(): int
    {
        return $this->reps;
    }

    /**
     * @return array<int, Row>
     */
    public function run(Environment $environment): array
    {
        $strata = $this->measure($environment, 'strata');
        $laravelFileDriver = $this->measure($environment, 'laravelFileDriver');

        return [
            new Row(
                label: self::HOLDS_LABEL,
                kind: RowKind::Count,
                strata: (float) $strata['holds'],
                laravelFileDriver: (float) $laravelFileDriver['holds'],
            ),
            new Row(
                label: 'no overlapping holds',
                kind: RowKind::Invariant,
                strata: $strata['noOverlaps'],
                laravelFileDriver: $laravelFileDriver['noOverlaps'],
            ),
        ];
    }

    /**
     * @param  array<int, AggregatedRow>  $rows
     */
    public function summary(array $rows, int $reps): string
    {
        $aggregatedRow = $this->rowByLabel($rows, self::HOLDS_LABEL);

        return sprintf(
            'Strata held the lock %.1f/%d times on average under contention, laravelFileDriver held it %.1f/%d times, over %d reps.',
            $aggregatedRow->strata,
            self::WORKERS,
            $aggregatedRow->laravelFileDriver,
            self::WORKERS,
            $reps,
        );
    }

    /**
     * @return array{holds: int, noOverlaps: bool}
     */
    private function measure(Environment $environment, string $driver): array
    {
        $name = 'lock-'.Str::random(8);

        $argsList = array_fill(0, self::WORKERS, [
            'lock', $driver, $environment->strataDirectory, $environment->fileCachePath, $name, (string) self::HOLD_MS,
        ]);

        $results = $environment->spawnConcurrently($argsList);

        $holds = array_values(array_filter(
            $results,
            fn (?array $result): bool => ($result['acquired'] ?? false) === true,
        ));

        return ['holds' => count($holds), 'noOverlaps' => $this->noOverlaps($holds)];
    }

    /**
     * @param  array<int, array<string, mixed>>  $holds
     */
    private function noOverlaps(array $holds): bool
    {
        usort($holds, fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $counter = count($holds);

        for ($i = 1; $i < $counter; $i++) {
            if ($holds[$i]['start'] < $holds[$i - 1]['end']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, AggregatedRow>  $rows
     */
    private function rowByLabel(array $rows, string $label): AggregatedRow
    {
        foreach ($rows as $row) {
            if ($row->label === $label) {
                return $row;
            }
        }

        throw new RuntimeException("No aggregated row found for label \"{$label}\".");
    }
}
