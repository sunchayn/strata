<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Modules\Cache;

use Illuminate\Cache\TagSet;
use Illuminate\Contracts\Cache\Store;
use Sunchayn\Strata\Modules\Cache\Services\TagsManager;

/**
 * A set holding tags to proxy caching operation on, such as, caching values within the tag.
 * This set is created when calling cache()->tags(...) and holds the specified tags.
 */
final class StrataTagSet extends TagSet
{
    /**
     * @param  array<int, string>  $names  Names of the tags in this set.
     */
    public function __construct(
        Store $store,
        private readonly TagsManager $tagsManager,
        array $names = [],
    ) {
        parent::__construct($store, $names);
    }

    public function resetTag($name): string
    {
        return $this->tagsManager->rotateId($name);
    }

    public function flushTag($name): void
    {
        $this->tagsManager->delete($name);
    }

    public function tagId($name): string
    {
        return $this->tagsManager->getIdOrCreate($name);
    }

    /**
     * Tags keys are not decorated in Strata, so we just return the name as is.
     * We store them as is in a separate folder away from cached data @see TagsManager
     * This method won't be called within Strata anyway but we leave it for potentional client app calls.
     */
    public function tagKey($name): string
    {
        return $name;
    }

    /**
     * Tags namespacing doesn't make sense in Strata so we just return an empty string @see StrataTaggedCache
     * This method won't be called within Strata anyway but we leave it for potentional client app calls.
     */
    public function getNamespace(): string
    {
        return '';
    }
}
