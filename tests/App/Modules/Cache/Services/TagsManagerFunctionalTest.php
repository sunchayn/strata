<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Tests\App\Modules\Cache\Services;

use Generator;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Sunchayn\Strata\Modules\Cache\Services\TagsManager;
use Sunchayn\Strata\Tests\TestCase;

#[CoversClass(TagsManager::class)]
final class TagsManagerFunctionalTest extends TestCase
{
    public function test_it_reads_an_id_persisted_by_another_instance(): void
    {
        // Arrange

        $id = resolve(TagsManager::class)->rotateId('assets');

        $this->app->forgetInstance(TagsManager::class);

        // Act & Assert

        $this->assertSame($id, resolve(TagsManager::class)->getId('assets')); // <- without the creation time.
    }

    public function test_it_returns_null_for_a_tag_that_has_no_id(): void
    {
        // Arrange

        $tagsManager = resolve(TagsManager::class);

        // Act & Assert

        $this->assertNull($tagsManager->getId('assets'));
    }

    public function test_it_serves_the_in_memory_id_without_reading_the_disk_again(): void
    {
        // Arrange

        $tagsManager = resolve(TagsManager::class);

        $id = $tagsManager->rotateId('assets');

        // Act

        (new Filesystem)->delete($this->tagFilepath('assets')); // <- the file is gone, the memory is not.

        // Assert

        $this->assertSame($id, $tagsManager->getId('assets'));
    }

    public function test_it_keeps_serving_a_missing_tag_from_memory_even_if_another_instance_creates_it(): void
    {
        // Arrange

        $tagsManager = resolve(TagsManager::class);

        $tagsManager->getId('assets'); // <- caches null in memory.

        $this->app->forgetInstance(TagsManager::class);

        resolve(TagsManager::class)->rotateId('assets'); // <- another instance writes the tag to disk.

        // Act & Assert

        $this->assertNull($tagsManager->getId('assets'));
    }

    public function test_it_treats_a_tag_file_that_vanishes_while_reading_as_missing(): void
    {
        // Arrange

        $filesystemMock = $this->mock(Filesystem::class);

        $tagsManager = resolve(TagsManager::class);

        // Anticipate

        $filesystemMock->allows('exists')->andReturn(true);

        $filesystemMock->allows('get')->andThrow(new FileNotFoundException);

        // Act & Assert

        $this->assertNull($tagsManager->getId('assets'));
    }

    public function test_it_creates_an_id_for_a_tag_that_has_none(): void
    {
        // Arrange

        [$expectedId] = $this->fakeTagIds(1);

        $tagsManager = resolve(TagsManager::class);

        // Act

        $id = $tagsManager->getIdOrCreate('assets');

        // Assert

        $this->assertSame($expectedId, $id);

        $this->assertSame($expectedId, $tagsManager->getId('assets'));

        $this->assertSame($expectedId, $this->tagIdOnDisk('assets'));
    }

    public function test_it_reuses_the_existing_id_instead_of_creating_a_new_one(): void
    {
        // Arrange

        $tagsManager = resolve(TagsManager::class);

        $id = $tagsManager->rotateId('assets');

        // Act & Assert

        $this->assertSame($id, $tagsManager->getIdOrCreate('assets'));
    }

    public function test_it_gives_each_tag_its_own_id(): void
    {
        // Arrange

        [$firstId, $secondId] = $this->fakeTagIds(2);

        $tagsManager = resolve(TagsManager::class);

        // Act

        $assetsId = $tagsManager->getIdOrCreate('assets');

        $relationsId = $tagsManager->getIdOrCreate('relations');

        // Assert

        $this->assertSame($firstId, $assetsId);

        $this->assertSame($secondId, $relationsId);
    }

    public function test_it_changes_the_id_when_a_tag_is_rotated(): void
    {
        // Arrange

        [$firstId, $secondId] = $this->fakeTagIds(2);

        $tagsManager = resolve(TagsManager::class);

        // Act

        $initialId = $tagsManager->rotateId('assets');

        $rotatedId = $tagsManager->rotateId('assets');

        // Assert

        $this->assertSame($firstId, $initialId);

        $this->assertSame($secondId, $rotatedId);

        $this->assertSame($secondId, $tagsManager->getId('assets'));

        $this->assertSame($secondId, $this->tagIdOnDisk('assets'));
    }

    public function test_it_persists_the_id_with_its_creation_time(): void
    {
        // Arrange

        $this->freezeTime();

        $tagsManager = resolve(TagsManager::class);

        // Act

        $id = $tagsManager->rotateId('assets');

        // Assert

        $this->assertSame(
            $id.'|'.now()->getTimestamp(),
            file_get_contents($this->tagFilepath('assets')),
        );
    }

    public function test_it_rotates_the_id_of_one_tag_without_touching_the_others(): void
    {
        // Arrange

        $tagsManager = resolve(TagsManager::class);

        $tagsManager->rotateId('assets');

        $relationsId = $tagsManager->rotateId('relations');

        // Act

        $tagsManager->rotateId('assets');

        // Assert

        // Assert that the relations IDs are intact.
        $this->assertSame($relationsId, $tagsManager->getId('relations'));
        $this->assertSame($relationsId, $this->tagIdOnDisk('relations'));
    }

    public function test_it_writes_the_tag_file_and_its_directory_with_the_configured_permission(): void
    {
        // Arrange

        config(['strata.file_permission' => 0o750]);

        $tagsManager = resolve(TagsManager::class);

        // Act

        $tagsManager->rotateId('assets');

        $filePermissionOnCreation = $this->permissionsOf($this->tagFilepath('assets'));

        $directoryPermissionOnCreation = $this->permissionsOf(dirname($this->tagFilepath('assets')));

        $tagsManager->rotateId('assets');

        $filePermissionAfterRotation = $this->permissionsOf($this->tagFilepath('assets'));

        // Assert

        $this->assertSame(0o750, $filePermissionOnCreation);

        $this->assertSame(0o750, $directoryPermissionOnCreation);

        $this->assertSame(0o750, $filePermissionAfterRotation);
    }

    public function test_it_leaves_no_temporary_file_behind_after_writing_an_id(): void
    {
        // Arrange

        $tagsManager = resolve(TagsManager::class);

        // Act

        $tagsManager->rotateId('assets');

        $tagsManager->rotateId('assets');

        // Assert

        $files = array_map('basename', glob($this->cacheDirectory.'/meta/tags/*'));

        $this->assertSame([sha1('assets').'.id'], $files);
    }

    #[DataProvider('unsafeTagNameProvider')]
    public function test_it_stores_any_tag_name_as_a_single_file_in_the_tags_directory(string $tag): void
    {
        // Arrange

        $tagsManager = resolve(TagsManager::class);

        // Act

        $id = $tagsManager->rotateId($tag);

        // Assert

        $this->assertSame($id, $tagsManager->getId($tag));

        $this->assertFileExists($this->tagFilepath($tag));

        $this->assertCount(1, glob($this->cacheDirectory.'/meta/tags/*'));
    }

    /**
     * @return Generator<string, array{tag: string}>
     */
    public static function unsafeTagNameProvider(): Generator
    {
        yield 'with a slash' => [
            'tag' => 'users/en',
        ];

        yield 'with a parent directory segment' => [
            'tag' => '../escape',
        ];

        yield 'with a pipe' => [
            'tag' => 'a|b',
        ];

        yield 'with spaces' => [
            'tag' => 'my tag',
        ];

        yield 'with unicode' => [
            'tag' => 'étiquette',
        ];

        yield 'numeric' => [
            'tag' => '123',
        ];
    }

    public function test_it_deletes_only_the_requested_tag(): void
    {
        // Arrange

        $tagsManager = resolve(TagsManager::class);

        $tagsManager->rotateId('assets');

        $relationsId = $tagsManager->rotateId('relations');

        // Act

        $tagsManager->delete('assets');

        // Assert

        $this->assertFileDoesNotExist($this->tagFilepath('assets'));

        $this->assertNull($tagsManager->getId('assets'));

        $this->assertSame($relationsId, $tagsManager->getId('relations'));

        $this->assertSame($relationsId, $this->tagIdOnDisk('relations'));
    }

    public function test_it_does_not_serve_a_stale_id_after_a_tag_was_deleted(): void
    {
        // Arrange

        [$staleId, $freshId] = $this->fakeTagIds(2);

        $tagsManager = resolve(TagsManager::class);

        $tagsManager->rotateId('assets');

        // Act

        $idBeforeDelete = $tagsManager->getId('assets'); // <- served from memory.

        $tagsManager->delete('assets');

        $idAfterDelete = $tagsManager->getIdOrCreate('assets');

        // Assert

        $this->assertSame($staleId, $idBeforeDelete);

        $this->assertSame($freshId, $idAfterDelete);
    }

    public function test_it_ignores_deleting_a_tag_that_does_not_exist(): void
    {
        // Arrange

        $tagsManager = resolve(TagsManager::class);

        // Act

        $tagsManager->delete('assets');

        // Assert

        $this->assertNull($tagsManager->getId('assets'));
    }

    public function test_it_removes_every_tag_when_flushed(): void
    {
        // Arrange

        $cachedFilepath = $this->createDummyCachedValue('key');

        $tagsManager = resolve(TagsManager::class);

        $tagsManager->rotateId('assets');

        $tagsManager->rotateId('relations');

        // Act

        $tagsManager->deleteAll();

        // Assert

        $this->assertNull($tagsManager->getId('assets'));
        $this->assertFileDoesNotExist($this->tagFilepath('assets'));

        $this->assertNull($tagsManager->getId('relations'));
        $this->assertFileDoesNotExist($this->tagFilepath('relations'));

        $this->assertSame([$cachedFilepath], $this->cachedValuesFilePaths()); // <- they will be deleted lazily on read.
    }

    public function test_it_does_not_serve_a_stale_id_after_a_flush(): void
    {
        // Arrange

        [$staleId, $freshId] = $this->fakeTagIds(2);

        $tagsManager = resolve(TagsManager::class);

        $tagsManager->rotateId('assets');

        // Act

        $idBeforeFlush = $tagsManager->getId('assets'); // <- served from memory.

        $tagsManager->deleteAll();

        $idAfterFlush = $tagsManager->getIdOrCreate('assets');

        // Assert

        $this->assertSame($staleId, $idBeforeFlush);

        $this->assertSame($freshId, $idAfterFlush);
    }

    public function test_it_ignores_flushing_when_no_tag_was_ever_written(): void
    {
        // Arrange

        $tagsManager = resolve(TagsManager::class);

        // Act

        $tagsManager->deleteAll();

        // Assert

        $this->assertNull($tagsManager->getId('assets'));

        $this->assertDirectoryDoesNotExist($this->cacheDirectory.'/meta/tags');
    }

    public function test_it_prunes_a_stale_tag_and_keeps_a_recent_one(): void
    {
        // Arrange

        $cachedFilepath = $this->createDummyCachedValue('key');

        $tagsManager = resolve(TagsManager::class);

        $tagsManager->rotateId('assets');

        $this->travel(100)->seconds();

        $relationsId = $tagsManager->rotateId('relations');

        $relationsFile = file_get_contents($this->tagFilepath('relations'));

        // Act

        $deleted = $tagsManager->prune(ttlInSecondsUntilStale: 50);

        // Assert

        $this->assertSame(1, $deleted);

        $this->assertFileDoesNotExist($this->tagFilepath('assets'));

        $this->assertSame($relationsFile, file_get_contents($this->tagFilepath('relations')));

        $this->assertSame($relationsId, $tagsManager->getId('relations'));

        $this->assertSame([$cachedFilepath], $this->cachedValuesFilePaths()); // <- cached values are left alone.
    }

    public function test_it_counts_every_tag_it_prunes(): void
    {
        // Arrange

        $tagsManager = resolve(TagsManager::class);

        $tagsManager->rotateId('assets');

        $tagsManager->rotateId('relations');

        $tagsManager->rotateId('users');

        $this->travel(100)->seconds();

        // Act

        $deleted = $tagsManager->prune(ttlInSecondsUntilStale: 50);

        // Assert

        $this->assertSame(3, $deleted);

        $this->assertSame([], glob($this->cacheDirectory.'/meta/tags/*'));
    }

    public function test_it_prunes_a_tag_that_is_one_second_older_than_the_ttl(): void
    {
        // Arrange

        $this->freezeTime();

        $tagsManager = resolve(TagsManager::class);

        $tagsManager->rotateId('assets');

        $this->travel(61)->seconds();

        // Act

        $deleted = $tagsManager->prune(ttlInSecondsUntilStale: 60);

        // Assert

        $this->assertSame(1, $deleted);

        $this->assertFileDoesNotExist($this->tagFilepath('assets'));
    }

    public function test_it_keeps_a_tag_that_is_exactly_as_old_as_the_ttl(): void
    {
        // Arrange

        $this->freezeTime();

        $tagsManager = resolve(TagsManager::class);

        $tagsManager->rotateId('assets');

        $this->travel(60)->seconds();

        // Act

        $deleted = $tagsManager->prune(ttlInSecondsUntilStale: 60);

        // Assert

        $this->assertSame(0, $deleted);

        $this->assertFileExists($this->tagFilepath('assets'));
    }

    public function test_it_prunes_a_stale_leftover_temporary_file(): void
    {
        // Arrange

        $tagsManager = resolve(TagsManager::class);

        $tagsManager->rotateId('assets');

        $temporaryFile = $this->cacheDirectory.'/meta/tags/'.sha1('assets').'.id.abcd1234.tmp';

        file_put_contents($temporaryFile, 'some-id|'.(now()->getTimestamp() - 100));

        // Act

        $deleted = $tagsManager->prune(ttlInSecondsUntilStale: 50);

        // Assert

        $this->assertSame(1, $deleted);

        $this->assertFileDoesNotExist($temporaryFile);
    }

    public function test_it_prunes_nothing_when_the_tags_directory_does_not_exist(): void
    {
        // Arrange

        $tagsManager = resolve(TagsManager::class);

        // Act & Assert

        $this->assertSame(0, $tagsManager->prune(ttlInSecondsUntilStale: 60));
    }

    #[DataProvider('malformedTagFileProvider')]
    public function test_it_skips_a_malformed_tag_file_when_pruning(string $contents): void
    {
        // Arrange

        $tagsManager = resolve(TagsManager::class);

        $tagsManager->rotateId('assets');

        file_put_contents($this->tagFilepath('assets'), $contents);

        // Act

        $deleted = $tagsManager->prune(ttlInSecondsUntilStale: 0);

        // Assert

        $this->assertSame(0, $deleted);

        $this->assertFileExists($this->tagFilepath('assets'));
    }

    /**
     * @return Generator<string, array{contents: string}>
     */
    public static function malformedTagFileProvider(): Generator
    {
        yield 'empty file' => [
            'contents' => '',
        ];

        yield 'id without a timestamp' => [
            'contents' => 'some-id',
        ];

        yield 'non numeric timestamp' => [
            'contents' => 'some-id|yesterday',
        ];

        yield 'empty timestamp' => [
            'contents' => 'some-id|',
        ];
    }
}
