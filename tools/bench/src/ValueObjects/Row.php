<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Bench\ValueObjects;

/**
 * One condition measured during a single scenario run, before averaging across reps.
 */
final class Row
{
    public readonly float $strata;

    public readonly float $laravelFileDriver;

    public function __construct(
        public readonly string $label,
        public readonly RowKind $kind,
        float|bool $strata,
        float|bool $laravelFileDriver,
        // An invariant that either held or was violated is stored as 1.0 or 0.0,
        // so the Runner can average every kind of row the same way, as a mean across reps.
        public readonly ?float $strataVariancePercent = null,
        public readonly ?float $laravelFileDriverVariancePercent = null,
    ) {
        $this->strata = (float) $strata;
        $this->laravelFileDriver = (float) $laravelFileDriver;
    }
}
