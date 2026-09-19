<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Bench\Contracts;

use Sunchayn\Strata\Bench\Support\Environment;
use Sunchayn\Strata\Bench\ValueObjects\AggregatedRow;
use Sunchayn\Strata\Bench\ValueObjects\Row;

/**
 * One measurable comparison between the strata driver and a stock Laravel driver.
 */
interface Scenario
{
    public function name(): string;

    /**
     * How many times the Runner should call run() and average across.
     */
    public function reps(): int;

    /**
     * @return array<int, Row> One row per condition measured, in a stable order every call.
     */
    public function run(Environment $environment): array;

    /**
     * A one-line factual takeaway built from the reps-averaged rows this scenario produced.
     *
     * @param  array<int, AggregatedRow>  $rows
     */
    public function summary(array $rows, int $reps): string;
}
