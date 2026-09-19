<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Tests\App;

use Generator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Sunchayn\Strata\Modules\Cache\Services\TagsManager;
use Sunchayn\Strata\Modules\Cache\StrataStore;
use Sunchayn\Strata\Modules\Cache\ValueObjects\TaggableValue;
use Sunchayn\Strata\StrataServiceProvider;
use Sunchayn\Strata\Tests\TestCase;

#[CoversClass(StrataServiceProvider::class)]
final class StrataServiceProviderFunctionalTest extends TestCase
{
    public function test_it_merges_the_default_config(): void
    {
        // Arrange

        $defaults = require __DIR__.'/../../config/strata.php';

        // Act & Assert

        $this->assertSame($defaults['directory'], storage_path('framework/cache/strata'));

        $this->assertSame(2_419_200, $defaults['tag_gc_ttl']);

        $this->assertSame(0o755, $defaults['file_permission']);

        $this->assertSame(2_419_200, config('strata.tag_gc_ttl'));

        $this->assertSame(0o755, config('strata.file_permission'));
    }

    public function test_it_shares_one_store_and_one_tags_manager(): void
    {
        // Act & Assert

        $this->assertSame(resolve(StrataStore::class), resolve(StrataStore::class));

        $this->assertSame(resolve(TagsManager::class), resolve(TagsManager::class));

        $this->assertSame(resolve(StrataStore::class), Cache::store('strata')->getStore());
    }

    public function test_it_gives_the_store_the_shared_tags_manager(): void
    {
        // Arrange

        $tagsManagerMock = $this->mock(TagsManager::class);

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('books:1', new TaggableValue('value', ['assets' => 'id-1']), 60);

        // Anticipate

        $tagsManagerMock->expects('getId')->with('assets')->andReturn('id-1');

        // Act

        $value = $strataStore->get('books:1');

        // Assert

        $this->assertSame('value', $value);
    }

    #[DataProvider('publishTagProvider')]
    public function test_it_publishes_the_config_file_under_the_tag(string $tag): void
    {
        // Act

        $paths = ServiceProvider::pathsToPublish(StrataServiceProvider::class, $tag);

        // Assert

        $this->assertCount(1, $paths);

        $this->assertFileEquals(__DIR__.'/../../config/strata.php', array_key_first($paths));

        $this->assertSame(config_path('strata.php'), array_values($paths)[0]);
    }

    /**
     * @return Generator<string, array{tag: string}>
     */
    public static function publishTagProvider(): Generator
    {
        yield 'package tag' => [
            'tag' => 'strata',
        ];

        yield 'config tag' => [
            'tag' => 'strata-config',
        ];
    }

    public function test_it_serves_the_default_cache_repository_through_the_strata_driver(): void
    {
        // Arrange

        config(['cache.default' => 'strata']);

        // Act & Assert

        $this->assertInstanceOf(StrataStore::class, Cache::getStore());
    }

    public function test_it_writes_cached_values_under_the_configured_directory(): void
    {
        // Arrange

        $filesystemMock = $this->mock(Filesystem::class)->shouldIgnoreMissing();

        // Anticipate

        $filesystemMock
            ->expects('put')
            ->withArgs(function (string $path): bool {
                $this->assertSame($this->cachedValueFilepath('books:1'), $path);

                return true;
            })
            ->andReturn(5);

        // Act & Assert

        resolve(StrataStore::class)->put('books:1', 'value', 60);
    }

    public function test_it_writes_tags_under_the_configured_directory(): void
    {
        // Arrange

        $filesystemMock = $this->mock(Filesystem::class)->shouldIgnoreMissing();

        // Anticipate

        $filesystemMock
            ->expects('move')
            ->withArgs(function (string $from, string $to): bool {
                $this->assertSame($this->tagFilepath('assets'), $to);

                return true;
            });

        // Act & Assert

        resolve(TagsManager::class)->rotateId('assets');
    }

    public function test_it_keeps_locks_under_a_locks_directory_of_the_configured_directory(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        // Act

        $strataStore->lock('orders', 60)->get();

        // Assert

        $this->assertTrue($strataStore->hasSeparateLockStore());

        $this->assertFileExists($this->lockFilepath('orders'));
    }

    public function test_it_gives_the_store_the_serializable_classes_of_the_cache_config(): void
    {
        // Arrange

        config(['cache.serializable_classes' => false]);

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('key', new stdClass, 60);

        // Act & Assert

        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $strataStore->get('key'));
    }
}
