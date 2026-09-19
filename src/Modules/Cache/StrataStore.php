<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Modules\Cache;

use Illuminate\Cache\Repository;
use Illuminate\Cache\TaggableStore;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\InteractsWithTime;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Sunchayn\Strata\Modules\Cache\Contracts\SafeLockProvider;
use Sunchayn\Strata\Modules\Cache\Exceptions\InvalidTagNameException;
use Sunchayn\Strata\Modules\Cache\Services\TagsManager;
use Sunchayn\Strata\Modules\Cache\ValueObjects\TaggableValue;
use Sunchayn\Strata\Modules\Lock\StrataLock;
use Sunchayn\Strata\Modules\Lock\StrataLockableFile;
use Sunchayn\Strata\Support\ManagesFilePermissions;
use Throwable;

/**
 * @phpstan-type CachedPayloadShape array{value: mixed, expires_at: int, tags?: array<string, string>}
 */
class StrataStore extends TaggableStore implements SafeLockProvider
{
    use InteractsWithTime;
    use ManagesFilePermissions;

    public const int FOREVER_TTL = 9_999_999_999;

    /**
     * @param  array<int, class-string>|bool|null  $serializableClasses  Classes allowed on unserialize, null allows any.
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly string $cacheDir,
        private readonly int $filePermission,
        private readonly TagsManager $tagsManager,
        private readonly string $lockDirectory,
        private readonly array|bool|null $serializableClasses = null,
    ) {
        if ($lockDirectory === $cacheDir) {
            throw new InvalidArgumentException('The lock directory must differ from the cache directory.');
        }
    }

    /**
     * @throws InvalidTagNameException
     */
    public function put($key, $value, $seconds): bool
    {
        $filepath = $this->getFilepath($key);

        $this->ensureCacheDirectoryExists($filepath);

        $result = $this->filesystem->put(
            path: $filepath,
            contents: $this->prepareForStorage($value, $seconds),
            lock: true,
        );

        if ($result !== false && $result > 0) {
            $this->setCorrectFilePermission($this->filesystem, $filepath, $this->filePermission);

            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $values  Values to store, keyed by cache key.
     */
    public function putMany(array $values, $seconds): bool
    {
        $finalResult = true;

        foreach ($values as $key => $value) {
            if (! $this->put($key, $value, $seconds)) {
                $finalResult = false;
            }
        }

        return $finalResult;
    }

    public function forever($key, $value): bool
    {
        return $this->put($key, $value, seconds: 0);
    }

    /**
     * Store the value only when the key is missing, expired, or has a flushed tag.
     *
     * @see Repository::add (calls this dynamically).
     *
     * @throws InvalidTagNameException
     */
    public function add(string $key, mixed $value, int $seconds): bool
    {
        $filepath = $this->getFilepath($key);

        $this->ensureCacheDirectoryExists($filepath);

        $lockableFile = StrataLockableFile::openExclusive($filepath);

        // The file is already being used by another process.
        if (! $lockableFile instanceof StrataLockableFile) {
            return false;
        }

        try {
            // A live key (metadata !== null) means we can bail early,
            // while an expired key or a flushed tag means the key is missing.
            if ($this->getMetadataOfCacheHit($lockableFile->handle()) !== null) {
                return false;
            }

            $lockableFile->overwrite(contents: $this->prepareForStorage($value, $seconds));
        } catch (InvalidTagNameException $invalidTagNameException) {
            // We create the file before the try-catch. We should undo that when this fails here.
            $this->filesystem->delete($filepath);

            throw $invalidTagNameException;
        } finally {
            $lockableFile->close();
        }

        $this->setCorrectFilePermission($this->filesystem, $filepath, $this->filePermission);

        return true;
    }

    public function get($key): mixed
    {
        return $this->readFromStorage($key)['value'] ?? null;
    }

    /**
     * @param  array<int, string>  $keys  Cache keys to look up.
     * @return array<string, mixed> Values keyed by the requested cache keys.
     */
    public function many(array $keys): array
    {
        $values = array_map(
            callback: fn (string $key): mixed => $this->get($key), array: $keys,
        );

        return array_combine($keys, $values);
    }

    /**
     * Read the current value and write the incremented one under a single held lock,
     * so a concurrent increment() on the same key can never read a value this one is about to overwrite.
     *
     * @throws RuntimeException When the file is deleted and recreated in the instant between opening it and locking it,
     *                          a transient race that is rare enough not to be worth retrying automatically.
     */
    public function increment($key, $value = 1): int
    {
        $filepath = $this->getFilepath($key);

        $this->ensureCacheDirectoryExists($filepath);

        $lockableFile = StrataLockableFile::openExclusive($filepath, blocking: true);

        // The file is already being used by another process.
        if (! $lockableFile instanceof StrataLockableFile) {
            throw new RuntimeException("Could not open \"{$key}\" for an atomic increment.");
        }

        try {
            $newValue = $this->incrementOverHeldFile($lockableFile, $value);
        } finally {
            $lockableFile->close();
        }

        $this->setCorrectFilePermission($this->filesystem, $filepath, $this->filePermission);

        return $newValue;
    }

    public function decrement($key, $value = 1): int
    {
        return $this->increment($key, $value * -1);
    }

    public function touch($key, $seconds): bool
    {
        $payload = $this->readFromStorage($key);

        if ($payload === null) {
            return false;
        }

        return $this->put(
            $key,
            new TaggableValue($payload['value'], $payload['tags'] ?? []),
            $seconds,
        );
    }

    public function forget($key): bool
    {
        $path = $this->getFilepath($key);

        if (! $this->filesystem->exists($path)) {
            return false;
        }

        $forgotten = $this->filesystem->delete($path);

        if ($forgotten) {
            $this->forgetFlexibleCreatedAt($key);
        }

        return $forgotten;
    }

    public function flush(): bool
    {
        if (! $this->filesystem->isDirectory($this->cacheDir)) {
            return false;
        }

        foreach ($this->filesystem->directories($this->cacheDir) as $directory) {
            $this->filesystem->deleteDirectory($directory);
        }

        $this->tagsManager->deleteAll();

        return true;
    }

    /**
     * @param  array<int, string>|string  $names  Tag name, or names, to scope this cache to.
     */
    public function tags($names): StrataTaggedCache
    {
        $tagSet = new StrataTagSet(
            store: $this,
            tagsManager: $this->tagsManager,
            names: is_array($names) ? $names : func_get_args(),
        );

        return new StrataTaggedCache(tagStore: $this, tags: $tagSet);
    }

    public function getPrefix(): string
    {
        // Not applicable for a filesystem driver, there won't be a collision.
        // Cache values are all within one namespaced directory `config('strata.directory')`.
        return '';
    }

    public function lock($name, $seconds = 0, $owner = null): StrataLock
    {
        return new StrataLock(
            filesystem: $this->filesystem,
            directory: $this->lockDirectory,
            filePermission: $this->filePermission,
            name: $name,
            seconds: $seconds,
            owner: $owner,
        );
    }

    public function restoreLock($name, $owner): StrataLock
    {
        return $this->lock($name, 0, $owner);
    }

    public function flushLocks(): bool
    {
        if (! $this->filesystem->isDirectory($this->lockDirectory)) {
            return false;
        }

        return $this->filesystem->deleteDirectory($this->lockDirectory, preserve: true);
    }

    /**
     * The lock directory always differs from the cache directory in Strata.
     */
    public function hasSeparateLockStore(): bool
    {
        return true;
    }

    /**
     * Build the cached file contents, which consist of three parts.
     *  - The expiration date, on the first line.
     *  - The tags as a JSON object, on the second line and empty when there are none.
     *  - The serialized value, which is the rest of the file.
     *
     * @throws InvalidTagNameException When a tag name is not valid UTF-8, so it cannot be encoded.
     */
    protected function prepareForStorage(mixed $value, int $ttlInSeconds): string
    {
        [$value, $tags] = $value instanceof TaggableValue ? $value->toArray() : [$value, []];

        // Other cache drivers namespace the keys with tag IDs, when flushed (id rotated) they leave slowly.
        // This approach is a no-go here in the filesystem given it will keep values dangling forever on the disk.
        // Instead, we embed the tags in the file itself so later we invalidate on read (similar to TTL).
        try {
            $tagsLine = $tags === [] ? '' : json_encode($tags, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw InvalidTagNameException::becauseNotEncodable($jsonException);
        }

        return $this->getExpiresAt($ttlInSeconds).PHP_EOL
            .$tagsLine.PHP_EOL
            .serialize($value);
    }

    /**
     * @return CachedPayloadShape|null The cached value, its expiry, and its tag ids.
     */
    protected function readFromStorage(string $key): ?array
    {
        $file = @fopen(filename: $this->getFilepath($key), mode: 'rb');

        if ($file === false) {
            return null;
        }

        try {
            // Puts and adds overwrite the file in place, so it is briefly empty.
            // A shared lock makes this read wait for that write to finish.
            // Without it, a half written file would look broken and get evicted.
            if (! flock($file, LOCK_SH)) {
                return null;
            }

            $metadata = $this->getMetadataOfCacheHit($file);

            throw_if($metadata === null);

            $payload = [
                'value' => $this->unserializeCachedValue((string) stream_get_contents($file)),
                'expires_at' => $metadata['expires_at'],
            ];

            if (! empty($metadata['tags'])) {
                $payload['tags'] = $metadata['tags'];
            }
        } catch (Throwable) {
            // Broken values, expired values, and values that their tags have been flushed are dropped from the cache on read.
            // This helps clean up the old cached values and keeps the caching directory smaller in size without forever hanging files.
            $this->forget($key);
        } finally {
            fclose($file);
        }

        return $payload ?? null;
    }

    /**
     * Get the metadata from the cached value payload, such as the expiration TTL and the tags.
     * Only return the metadata of a cached value that is still readable (not expired or flushed).
     *
     * @param  resource  $file
     * @return array{expires_at: int, tags: array<string, string>}|null
     */
    protected function getMetadataOfCacheHit($file): ?array
    {
        $expiresAtLine = rtrim((string) fgets($file), "\n");

        if (! ctype_digit($expiresAtLine)) {
            return null;
        }

        $expiresAt = (int) $expiresAtLine;

        if ($this->currentTime() >= $expiresAt) {
            return null;
        }

        $tagsLine = rtrim((string) fgets($file), "\n");

        $tags = $tagsLine === '' ? [] : json_decode($tagsLine, associative: true);

        if (! is_array($tags)) {
            return null;
        }

        if ($this->hasExpiredTag($tags)) {
            return null;
        }

        return [
            'expires_at' => $expiresAt,
            'tags' => $tags,
        ];
    }

    /**
     * @throws Throwable
     */
    protected function unserializeCachedValue(string $serialized): mixed
    {
        $value = $this->serializableClasses === null
            ? unserialize($serialized)
            : unserialize($serialized, ['allowed_classes' => $this->serializableClasses]);

        // When unserialize fails, it will return `false`.
        // This shouldn't be confused with a serialized false, hence the 2nd condition.
        throw_if($value === false && $serialized !== 'b:0;');

        return $value;
    }

    /**
     * Read the current value from an already-locked file, add the value, and write the result back in place.
     */
    protected function incrementOverHeldFile(StrataLockableFile $lock, int $value): int
    {
        $current = $this->readPayloadOfCacheHit($lock->handle());

        // To keep parity with Laravel's file driver on cache miss,
        // a missing, expired, or flushed-tag key is created and kept forever (ttl = 0).
        if ($current === null) {
            $lock->overwrite(
                contents: $this->prepareForStorage(
                    value: $value,
                    ttlInSeconds: 0,
                )
            );

            return $value;
        }

        $newValue = ((int) $current['value']) + $value;

        $lock->overwrite(
            contents: $this->prepareForStorage(
                value: new TaggableValue($newValue, $current['tags'] ?? []),
                // The remaining TTL in seconds, so the incremented value keeps its original expiry.
                ttlInSeconds: $current['expires_at'] - $this->currentTime(),
            ),
        );

        return $newValue;
    }

    /**
     * @param  resource  $handle
     * @return CachedPayloadShape|null The cached value, its expiry, and its tag ids, or null when it is unreadable.
     */
    protected function readPayloadOfCacheHit($handle): ?array
    {
        $metadata = $this->getMetadataOfCacheHit($handle);

        if ($metadata === null) {
            return null;
        }

        try {
            $value = $this->unserializeCachedValue((string) stream_get_contents($handle));
        } catch (Throwable) {
            return null;
        }

        return ['value' => $value, ...$metadata];
    }

    /**
     * @param  array<string, string>  $tags
     */
    protected function hasExpiredTag(array $tags): bool
    {
        foreach ($tags as $tag => $id) {
            if ($this->tagsManager->getId((string) $tag) === $id) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Get the expiration time based on the given seconds.
     */
    protected function getExpiresAt(int $ttlInSeconds): int
    {
        $availableAtTs = $this->availableAt($ttlInSeconds);

        return $ttlInSeconds === 0 || $availableAtTs > self::FOREVER_TTL
            ? self::FOREVER_TTL
            : $availableAtTs;
    }

    /**
     * Create the file cache directories if necessary.
     */
    protected function ensureCacheDirectoryExists(string $filepath): void
    {
        $directory = dirname($filepath);

        if ($this->filesystem->exists($directory)) {
            return;
        }

        $this->makeDirectoryWithPermission($this->filesystem, $directory, $this->filePermission);
    }

    /**
     * A flexible() cache entry stores its creation time under an auxiliary key alongside the real one.
     */
    protected function forgetFlexibleCreatedAt(string $key): void
    {
        $path = $this->getFilepath(Repository::FLEXIBLE_CREATED_KEY_PREFIX.$key);

        if ($this->filesystem->exists($path)) {
            $this->filesystem->delete($path);
        }
    }

    protected function getFilepath(string $key): string
    {
        $hash = sha1($key);

        // Splitting the hash into two levels of two-character directories, for example a1/bb from [a1][bb]c3a44f5...,
        // this spreads cached values across up to 65536* buckets, so directories don't slow down their lookups.
        // *sha1 produced hex digits (16), with 2 digits per folder then total buckets is 16^2 x 16^2 = 65536.
        return $this->cacheDir.'/'.substr($hash, 0, 2).'/'.substr($hash, 2, 2).'/'.$hash;
    }
}
