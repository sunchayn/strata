<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Console\Commands;

use Illuminate\Console\Command;
use Sunchayn\Strata\Modules\Cache\Services\TagsManager;

class PruneStaleCacheTagsCommand extends Command
{
    protected $signature = 'strata:prune-stale-tags';

    protected $description = 'Delete strata cache tag id files that have not been flushed recently.';

    public function handle(TagsManager $tags): int
    {
        $deleted = $tags->prune(ttlInSecondsUntilStale: (int) config('strata.tag_gc_ttl'));

        \Laravel\Prompts\info("Pruned {$deleted} stale cache tag file(s).");

        return self::SUCCESS;
    }
}
