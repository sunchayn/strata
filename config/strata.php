<?php

declare(strict_types=1);
use Carbon\CarbonInterval;

return [

    /*
    |--------------------------------------------------------------------------
    | Cache Directory
    |--------------------------------------------------------------------------
    |
    | This is the root directory the StrataStore reads from and writes to.
    |
    */

    'directory' => storage_path('framework/cache/strata'),

    /*
    |--------------------------------------------------------------------------
    | Tag Garbage Collection TTL
    |--------------------------------------------------------------------------
    |
    | A tag's id file is never deleted just because nothing references it anymore.
    | This value, in seconds, sets how long one is kept idle before the tag-pruning command removes it.
    | Schedule the `strata:prune-stale-tags` commands to run periodically to garbage collect the tags.
    |
    */

    'tag_gc_ttl' => (int) CarbonInterval::month()->totalSeconds,

    /*
    |--------------------------------------------------------------------------
    | Cache File Permission
    |--------------------------------------------------------------------------
    |
    | Octal permission applied to every data file, id file, and directory the Strata creates,
    | useful when multiple processes under different users need to share the same cache directory.
    |
    */

    'file_permission' => 0o755,

];
