<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Modules\Cache\Services;

use Illuminate\Cache\Console\PruneStaleTagsCommand;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\InteractsWithTime;
use Illuminate\Support\Str;
use RuntimeException;
use Sunchayn\Strata\Support\ManagesFilePermissions;

/**
 * Strata stores the tags in a separate directory than the main cached data.
 * Each tag is a file that has the current ID and creation timestamp of the tag.
 *
 * @example <uuid>|<unix time>
 */
class TagsManager
{
    use InteractsWithTime;
    use ManagesFilePermissions;

    /**
     * @var array<string, string|null> Tag ids already loaded in this process, keyed by tag name.
     */
    private array $inMemoryTags = [];

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly string $directory,
        private readonly int $filePermission,
    ) {}

    /**
     * Get the current ID of the tag. The ID is rotated every time a tag is flushed.
     */
    public function getId(string $tag): ?string
    {
        if (array_key_exists($tag, $this->inMemoryTags)) {
            return $this->inMemoryTags[$tag];
        }

        $filepath = $this->getFilepath($tag);

        if (! $this->filesystem->exists($filepath)) {
            return $this->inMemoryTags[$tag] = null;
        }

        try {
            [$id] = explode('|', $this->filesystem->get($filepath), 2);
        } catch (FileNotFoundException) {
            return null;
        }

        return $this->inMemoryTags[$tag] = $id;
    }

    /**
     * Get the current ID of the tag or create a new one.
     */
    public function getIdOrCreate(string $tag): string
    {
        return $this->getId($tag) ?? $this->rotateId($tag);
    }

    public function rotateId(string $tag): string
    {
        $this->ensureTagsDirectoryExists();

        $newId = $this->generateTagId();

        /**
         * We stamp the IDs with the creation time so that we can prune old tags on-demand if needed.
         *
         * @see PruneStaleTagsCommand
         */
        $fileContent = $newId.'|'.$this->currentTime();

        $filepath = $this->getFilepath($tag);

        $this->writeTagFile($filepath, $fileContent);

        $this->setCorrectFilePermission($this->filesystem, $filepath, $this->filePermission);

        return $this->inMemoryTags[$tag] = $newId;
    }

    public function delete(string $tag): void
    {
        unset($this->inMemoryTags[$tag]);

        $this->filesystem->delete($this->getFilepath($tag));
    }

    public function deleteAll(): void
    {
        $this->flushInMemoryTags();

        if (! $this->filesystem->isDirectory($this->directory)) {
            return;
        }

        $this->filesystem->deleteDirectory($this->directory, preserve: true);
    }

    /**
     * Prune tags that were sitting in disk longer than the provided TTL in seconds.
     *
     * @param  int  $ttlInSecondsUntilStale  The TTL in seconds difference (now vs creation) to consider stale.
     * @return int The number of pruned tags.
     */
    public function prune(int $ttlInSecondsUntilStale): int
    {
        if (! $this->filesystem->isDirectory($this->directory)) {
            return 0;
        }

        $deleted = 0;

        foreach ($this->filesystem->files($this->directory) as $file) {
            try {
                $contents = $file->getContents();
            } catch (RuntimeException) {
                continue;
            }

            $parts = explode('|', $contents, 2);

            if (count($parts) !== 2) {
                continue;
            }

            if (! is_numeric($parts[1])) {
                continue;
            }

            $createdAtUnixTs = (int) $parts[1];

            if ($this->currentTime() - $createdAtUnixTs <= $ttlInSecondsUntilStale) {
                continue;
            }

            $this->filesystem->delete($file->getPathname());

            $deleted++;
        }

        return $deleted;
    }

    protected function ensureTagsDirectoryExists(): void
    {
        if ($this->filesystem->isDirectory($this->directory)) {
            return;
        }

        $this->makeDirectoryWithPermission($this->filesystem, $this->directory, $this->filePermission);
    }

    protected function generateTagId(): string
    {
        return Str::uuid()->toString();
    }

    protected function writeTagFile(string $path, string $fileContent): void
    {
        // Write a file is not an instantaneous operation, it has to go through some steps (internally).
        // So we shouldn't allow reading mid-writing to avoid any unexpected side effects with the caching.
        // We keep the read/write safely isolated we first operate on a temp file then rename (atomic operation).
        $tempPath = $path.'.'.Str::random(8).'.tmp';

        $this->filesystem->put($tempPath, $fileContent);

        $this->filesystem->move($tempPath, $path);
    }

    protected function flushInMemoryTags(): void
    {
        $this->inMemoryTags = [];
    }

    protected function getFilepath(string $tag): string
    {
        // Tag names are still hashed to neutralize any side effects from the names the user might use.
        // For instance, a tag like `users/en` would create a sub-folder is not neutralized.
        return $this->directory.'/'.sha1($tag).'.id';
    }
}
