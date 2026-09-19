<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Bench\ValueObjects;

/**
 * How a row's strata and laravelFileDriver values should be aggregated across reps and rendered.
 */
enum RowKind
{
    case Duration;
    case Count;
    case Invariant;
}
