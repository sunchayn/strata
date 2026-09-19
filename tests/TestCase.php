<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use LogicException;
use Orchestra\Testbench\TestCase as Orchestra;
use Ramsey\Uuid\Uuid;
use Sunchayn\Strata\StrataServiceProvider;
use Symfony\Component\Finder\SplFileInfo;

abstract class TestCase extends Orchestra
{
    protected string $cacheDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheDirectory = sys_get_temp_dir().'/strata-tests-'.Str::uuid();

        config([
            'strata.directory' => $this->cacheDirectory,
            'cache.stores.strata' => ['driver' => 'strata'],
        ]);
    }

    protected function tearDown(): void
    {
        Str::createUuidsNormally();

        (new Filesystem)->deleteDirectory($this->cacheDirectory);

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            StrataServiceProvider::class,
        ];
    }

    /**
     * Make the next tag ids predictable, one for each tag creation or rotation.
     *
     * @return array<int, string> The ids in the order they will be handed out.
     */
    protected function fakeTagIds(int $count): array
    {
        $ids = array_map(
            callback: fn (int $number): string => sprintf('00000000-0000-4000-8000-%012d', $number),
            array: range(1, $count),
        );

        Str::createUuidsUsingSequence(
            sequence: array_map(Uuid::fromString(...), $ids),
            whenMissing: fn () => throw new LogicException("More than {$count} tag ids were generated."),
        );

        return $ids;
    }

    protected function cachedValueFilepath(string $key): string
    {
        $hash = sha1($key);

        return $this->cacheDirectory.'/data/'.substr($hash, 0, 2).'/'.substr($hash, 2, 2).'/'.$hash;
    }

    protected function lockFilepath(string $name): string
    {
        return $this->cacheDirectory.'/locks/'.sha1($name);
    }

    /**
     * The `fileperms()` returns the permission bits mixed with the file type, for example 0o100750 for a regular file.
     * This strips it down to just the permission bits, for example 0o750, so a test can compare it directly.
     */
    protected function permissionsOf(string $path): int
    {
        return fileperms($path) & 0o777;
    }

    /**
     * Create a cached value file on the disk without going through the store.
     */
    protected function createDummyCachedValue(string $key, string $content = 'payload'): string
    {
        $filepath = $this->cachedValueFilepath($key);

        (new Filesystem)->ensureDirectoryExists(dirname($filepath));

        file_put_contents($filepath, $content);

        return $filepath;
    }

    /**
     * Read a cached value file as it is stored on the disk, split into its three parts.
     *
     * @return array<string, mixed>
     */
    protected function cachedValuePayload(string $key): array
    {
        [$expiresAt, $tags, $value] = explode("\n", (string) file_get_contents($this->cachedValueFilepath($key)), 3);

        $payload = ['value' => unserialize($value), 'expires_at' => (int) $expiresAt];

        if ($tags !== '') {
            $payload['tags'] = json_decode($tags, associative: true);
        }

        return $payload;
    }

    protected function tagFilepath(string $tag): string
    {
        return $this->cacheDirectory.'/meta/tags/'.sha1($tag).'.id';
    }

    /**
     * Read the tag id from the disk, bypassing the in-memory copy held by the tags manager.
     */
    protected function tagIdOnDisk(string $tag): ?string
    {
        $filepath = $this->tagFilepath($tag);

        return file_exists($filepath) ? explode('|', (string) file_get_contents($filepath))[0] : null;
    }

    /**
     * @return array<int, string> The paths of every cached file on disk.
     */
    protected function cachedValuesFilePaths(): array
    {
        $filesystem = new Filesystem;

        if (! $filesystem->isDirectory($this->cacheDirectory.'/data')) {
            return [];
        }

        return array_map(
            callback: fn (SplFileInfo $file): string => $file->getPathname(),
            array: $filesystem->allFiles($this->cacheDirectory.'/data'),
        );
    }
}
