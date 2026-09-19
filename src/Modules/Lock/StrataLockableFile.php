<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Modules\Lock;

/**
 * A file handle that holds a lock until it is closed.
 *
 * @see StrataLock::acquire
 */
final class StrataLockableFile
{
    /**
     * Only openExclusive creates instances, so an instance always holds the lock.
     *
     * @param  resource  $handle
     */
    private function __construct(private $handle, private readonly string $path) {}

    /**
     * Open the file, creating it when missing unless $create is false, and lock it exclusively.
     * Returns null when the file cannot be opened, another process holds the lock, or the file was swapped.
     *
     * @param  bool  $blocking  when true, the process will wait to acquire the lock instead of returning null
     */
    public static function openExclusive(string $path, bool $create = true, bool $blocking = false): ?self
    {
        $handle = @fopen($path, $create ? 'c+' : 'r+');

        if ($handle === false) {
            return null;
        }

        if (! flock($handle, $blocking ? LOCK_EX : LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        $file = new self($handle, $path);

        // The file can be deleted or replaced between opening it and locking it.
        // We then hold a lock on a file nobody sees anymore, so we bail out.
        if (! $file->isHandleStillValid()) {
            $file->close();

            return null;
        }

        return $file;
    }

    /**
     * Whether the path still points to the file this handle has open.
     * It is false once the file was deleted, or deleted and created again.
     */
    public function isHandleStillValid(): bool
    {
        // We clear stats cache to see a deletion or replacement that just happened.
        clearstatcache(true, $this->path);

        $onDiskFileStats = @stat($this->path);

        if ($onDiskFileStats === false) {
            return false;
        }

        $handleFileStats = fstat($this->handle);

        if ($handleFileStats === false) {
            return false;
        }

        return $onDiskFileStats['ino'] === $handleFileStats['ino'];
    }

    /**
     * Exposed so callers can read the current contents while they hold the lock.
     *
     * @return resource
     */
    public function handle()
    {
        return $this->handle;
    }

    public function overwrite(string $contents): void
    {
        rewind($this->handle);
        ftruncate($this->handle, 0);
        fwrite($this->handle, $contents);
        fflush($this->handle);
    }

    public function close(): void
    {
        if (! is_resource($this->handle)) {
            return;
        }

        flock($this->handle, LOCK_UN);

        fclose($this->handle);
    }
}
