<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Bench\ValueObjects;

/**
 * A row's strata and laravelFileDriver values, averaged across every rep the Runner performed.
 */
final readonly class AggregatedRow
{
    public function __construct(
        public string $label,
        public RowKind $kind,
        public float $strata,
        public float $laravelFileDriver,
        // The variance fields are only meaningful for a Duration row, null otherwise.
        public ?float $strataVariancePercent = null,
        public ?float $laravelFileDriverVariancePercent = null,
    ) {}
}
