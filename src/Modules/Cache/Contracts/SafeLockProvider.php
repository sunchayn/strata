<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Modules\Cache\Contracts;

use Illuminate\Contracts\Cache\CanFlushLocks;
use Illuminate\Contracts\Cache\LockProvider;

// Laravel 13 added support to `CanFlushLocks`, that Laravel 12 doesn't have.
// This contract picks the right parents so the store loads on both versions.
if (interface_exists(CanFlushLocks::class)) {
    interface SafeLockProvider extends CanFlushLocks, LockProvider {}
} else {
    interface SafeLockProvider extends LockProvider {}
}
