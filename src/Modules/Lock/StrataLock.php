<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Modules\Lock;

use Illuminate\Cache\Lock;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\InteractsWithTime;
use Sunchayn\Strata\Modules\Cache\StrataStore;
use Sunchayn\Strata\Support\ManagesFilePermissions;

/**
 * A lock kept in one plain text file, named after the SHA-1 of the lock name.
 * The first line is the expiry as a Unix time, and the rest of the file is the owner.
 */
class StrataLock extends Lock
{
    use InteractsWithTime;
    use ManagesFilePermissions;

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly string $directory,
        private readonly int $filePermission,
        string $name,
        int $seconds,
        ?string $owner = null,
    ) {
        parent::__construct($name, $seconds, $owner);
    }

    public function acquire(): bool
    {
        $this->ensureDirectoryExists();

        $lockableFile = StrataLockableFile::openExclusive($this->filepath());

        if (! $lockableFile instanceof StrataLockableFile) {
            return false;
        }

        try {
            if ($this->getOwnerIfNotExpired($lockableFile->handle()) !== null) {
                return false;
            }

            $lockableFile->overwrite($this->prepareForStorage($this->seconds));
        } finally {
            $lockableFile->close();
        }

        $this->setCorrectFilePermission($this->filesystem, $this->filepath(), $this->filePermission);

        return true;
    }

    public function release(): bool
    {
        $lockableFile = $this->openOwned();

        if (! $lockableFile instanceof StrataLockableFile) {
            return false;
        }

        try {
            return $this->filesystem->delete($this->filepath());
        } finally {
            $lockableFile->close();
        }
    }

    public function forceRelease(): void
    {
        $this->filesystem->delete($this->filepath());
    }

    /**
     * Extend the expiry of the lock, only when this owner still holds it.
     */
    public function refresh($seconds = null): bool
    {
        $lockableFile = $this->openOwned();

        if (! $lockableFile instanceof StrataLockableFile) {
            return false;
        }

        try {
            $lockableFile->overwrite($this->prepareForStorage($seconds ?? $this->seconds));

            return true;
        } finally {
            $lockableFile->close();
        }
    }

    // The Laravel's Lock only defines isLocked() from Laravel 13 onward.
    // We define our own here so it works on the L12 that we support.
    public function isLocked(): bool
    {
        return $this->getCurrentOwner() !== null;
    }

    protected function getCurrentOwner(): ?string
    {
        $file = @fopen($this->filepath(), 'rb');

        if ($file === false) {
            return null;
        }

        try {
            // Locks are overwritten in place, so the lock file is briefly empty during replacement.
            // So we use a shared lock here so that this read wait for the write to finish.
            if (! flock($file, LOCK_SH)) {
                return null;
            }

            return $this->getOwnerIfNotExpired($file);
        } finally {
            fclose($file);
        }
    }

    /**
     * Open the existing lock file under an exclusive lock, only when this owner still holds it.
     * A missing lock is never created here, so a failed release leaves nothing behind.
     */
    protected function openOwned(): ?StrataLockableFile
    {
        $lockableFile = StrataLockableFile::openExclusive($this->filepath(), create: false, blocking: true);

        if (! $lockableFile instanceof StrataLockableFile) {
            return null;
        }

        if ($this->getOwnerIfNotExpired($lockableFile->handle()) !== $this->owner) {
            $lockableFile->close();

            return null;
        }

        return $lockableFile;
    }

    /**
     * @param  resource  $lockableFile
     * @return string|null The owner, or null when the file is empty, malformed, or expired.
     */
    protected function getOwnerIfNotExpired($lockableFile): ?string
    {
        $expiresLine = rtrim((string) fgets($lockableFile), "\n");

        if (! ctype_digit($expiresLine) || $this->currentTime() >= (int) $expiresLine) {
            return null;
        }

        return (string) stream_get_contents($lockableFile);
    }

    protected function prepareForStorage(int $ttlInSeconds): string
    {
        $availableAt = $this->availableAt($ttlInSeconds);

        $expiresAt = $ttlInSeconds <= 0 || $availableAt > StrataStore::FOREVER_TTL
            ? StrataStore::FOREVER_TTL
            : $availableAt;

        return $expiresAt."\n".$this->owner;
    }

    protected function ensureDirectoryExists(): void
    {
        if ($this->filesystem->isDirectory($this->directory)) {
            return;
        }

        $this->makeDirectoryWithPermission($this->filesystem, $this->directory, $this->filePermission);
    }

    protected function filepath(): string
    {
        return $this->directory.'/'.sha1($this->name);
    }
}
