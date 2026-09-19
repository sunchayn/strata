<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Modules\Cache;

use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Cache\TaggedCache;
use Sunchayn\Strata\Modules\Cache\ValueObjects\TaggableValue;
use UnitEnum;

/**
 * By default, Laravel tagged cache is done via keys namespacing with tag IDs (rotated on flush).
 * Namespacing the keys will not work for the filesystem as it will leave dangling values forever.
 * Therefore, this class is redirecting the default logic from namespacing to tagging in the payload.
 *
 * @see StrataStore::prepareForStorage()
 */
class StrataTaggedCache extends TaggedCache
{
    /**
     * @var array<string, string>
     */
    private array $namedIds;

    public function __construct(
        StrataStore $tagStore,
        StrataTagSet $tags,
    ) {
        parent::__construct($tagStore, $tags);

        $this->initializeNameIds();
    }

    /**
     * @param  UnitEnum|array<int|string, mixed>|string  $key
     */
    public function put($key, $value, $ttl = null): bool
    {
        if (is_array($key)) {
            return $this->putMany($key, $value);
        }

        return parent::put(
            key: $key,
            value: $this->wrap($value),
            ttl: $ttl,
        );
    }

    /**
     * @param  UnitEnum|string  $key
     */
    public function add($key, $value, $ttl = null): bool
    {
        return parent::add(
            key: $key,
            value: $this->wrap($value),
            ttl: $ttl,
        );
    }

    /**
     * @param  UnitEnum|string  $key
     */
    public function forever($key, $value): bool
    {
        return parent::forever(
            key: $key,
            value: $this->wrap($value),
        );
    }

    /**
     * A flush rotates the tag ids, so we reload them to read the new ids.
     */
    public function flush(): bool
    {
        $flushed = parent::flush();

        $this->initializeNameIds();

        return $flushed;
    }

    /**
     * Reset the parent class's itemKey logic to undo the key namespacing.
     */
    protected function itemKey($key): string
    {
        return $key;
    }

    /**
     * Fire an event for this cache instance.
     * We are overriding this to unwarp the value in some events,
     * so that the consumers are never aware of the internals of Strata.
     *
     * @param  object|string  $event
     */
    protected function event($event): void
    {
        if (! is_object($event)) {
            $this->events?->dispatch($event);

            return;
        }

        // These events fire from put(...) or forever(...) methods.
        // We are wrapping these (see above), so we target only these for the unwrapping.
        if ($event instanceof WritingKey || $event instanceof KeyWritten || $event instanceof KeyWriteFailed) {
            $event->value = $event->value instanceof TaggableValue
                ? $event->value->unwrap()
                : $event->value;
        }

        $this->events?->dispatch($event);
    }

    /**
     * Wrap the value within a `TaggableValue` instance so that Strata can properly tag it when called.
     * Note this "store" is some sort of proxy, it forwards cache manipulation operations to the store (Strata).
     */
    protected function wrap(mixed $value): TaggableValue
    {
        // A null TTL makes put() call forever(), so the value may already be wrapped.
        return $value instanceof TaggableValue
            ? $value
            : new TaggableValue($value, $this->namedIds);
    }

    protected function initializeNameIds(): void
    {
        $values = array_map(
            callback: fn (string $name) => $this->tags->tagId($name),
            array: $this->tags->getNames(),
        );

        $this->namedIds = array_combine($this->tags->getNames(), $values);
    }
}
