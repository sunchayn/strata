<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Bench\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A standalone Eloquent model, so DataShapeBench can measure caching a real model.
 */
final class BenchUser extends Model
{
    protected $guarded = [];
}
