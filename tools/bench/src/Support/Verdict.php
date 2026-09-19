<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Bench\Support;

use Sunchayn\Strata\Bench\ValueObjects\AggregatedRow;

/**
 * The strata-vs-laravelFileDriver speed comparison, shared by every report that renders a Duration row.
 */
final class Verdict
{
    private const float TIE_THRESHOLD_PERCENT = 3.0;

    /**
     * A gap smaller than this is a tie regardless of its percentage,
     * since a fraction of a microsecond can read as a large relative difference,
     * once both values are themselves close to zero.
     */
    private const float TIE_MINIMUM_ABSOLUTE_MS = 0.01;

    public static function speed(float $strata, float $laravelFileDriver): string
    {
        if ($strata <= 0.0 || $laravelFileDriver <= 0.0) {
            return '≈ tie';
        }

        if (abs($strata - $laravelFileDriver) < self::TIE_MINIMUM_ABSOLUTE_MS) {
            return '≈ tie';
        }

        $diffPercent = abs($strata - $laravelFileDriver) / max($strata, $laravelFileDriver) * 100;

        if ($diffPercent < self::TIE_THRESHOLD_PERCENT) {
            return '≈ tie';
        }

        return $strata < $laravelFileDriver
            ? sprintf('strata ~%d%% faster', round($diffPercent))
            : sprintf('laravelFileDriver ~%d%% faster', round($diffPercent));
    }

    /**
     * A one-line tally of how many rows each driver won, using the same tie-aware verdict as speed().
     *
     * @param  array<int, AggregatedRow>  $rows
     */
    public static function summarize(array $rows): string
    {
        if ($rows === []) {
            return 'No measurements taken.';
        }

        $verdicts = array_map(fn (AggregatedRow $row): string => self::speed($row->strata, $row->laravelFileDriver), $rows);

        $strataFaster = count(array_filter($verdicts, fn (string $verdict): bool => str_starts_with($verdict, 'strata')));
        $laravelFileDriverFaster = count(array_filter($verdicts, fn (string $verdict): bool => str_starts_with($verdict, 'laravelFileDriver')));
        $tied = count($rows) - $strataFaster - $laravelFileDriverFaster;

        return sprintf(
            'Strata was faster on %d/%d measurements, laravelFileDriver on %d/%d, tied on %d/%d.',
            $strataFaster,
            count($rows),
            $laravelFileDriverFaster,
            count($rows),
            $tied,
            count($rows),
        );
    }
}
