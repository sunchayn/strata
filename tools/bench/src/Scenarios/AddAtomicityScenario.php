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
 * Fires add() on the same key from several real processes at once.
 * Exactly one should win, regardless of driver, since both hold an exclusive file lock while checking and writing.
 */
final class AddAtomicityScenario implements Scenario
{
    private const int WORKERS = 16;

    private const string SUCCESSES_LABEL = 'successful add() out of '.self::WORKERS;

    public function __construct(private readonly int $reps = 5) {}

    public function name(): string
    {
        return sprintf('add() atomicity under %d concurrent processes', self::WORKERS);
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
        $strataSuccesses = $this->successfulAdds($environment, 'strata');
        $laravelFileDriverSuccesses = $this->successfulAdds($environment, 'laravelFileDriver');

        return [
            new Row(
                label: self::SUCCESSES_LABEL,
                kind: RowKind::Count,
                strata: (float) $strataSuccesses,
                laravelFileDriver: (float) $laravelFileDriverSuccesses,
            ),
            new Row(
                label: 'exactly-once invariant',
                kind: RowKind::Invariant,
                strata: $strataSuccesses === 1,
                laravelFileDriver: $laravelFileDriverSuccesses === 1,
            ),
        ];
    }

    /**
     * @param  array<int, AggregatedRow>  $rows
     */
    public function summary(array $rows, int $reps): string
    {
        $aggregatedRow = $this->rowByLabel($rows, self::SUCCESSES_LABEL);

        return sprintf(
            'Strata: %.1f/%d add() succeeded on average. LaravelFileDriver: %.1f/%d succeeded on average, over %d reps. Exactly one is the correct outcome for both.',
            $aggregatedRow->strata,
            self::WORKERS,
            $aggregatedRow->laravelFileDriver,
            self::WORKERS,
            $reps,
        );
    }

    private function successfulAdds(Environment $environment, string $driver): int
    {
        $key = 'atomic-'.Str::random(8);

        $argsList = array_fill(0, self::WORKERS, [
            'add', $driver, $environment->strataDirectory, $environment->fileCachePath, $key,
        ]);

        $results = $environment->spawnConcurrently($argsList);

        return count(array_filter(
            $results,
            fn (?array $result): bool => ($result['success'] ?? false) === true,
        ));
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
