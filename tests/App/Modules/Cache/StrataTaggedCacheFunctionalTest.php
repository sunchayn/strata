<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Tests\App\Modules\Cache;

use Generator;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Sunchayn\Strata\Modules\Cache\Services\TagsManager;
use Sunchayn\Strata\Modules\Cache\StrataStore;
use Sunchayn\Strata\Modules\Cache\StrataTaggedCache;
use Sunchayn\Strata\Modules\Cache\StrataTagSet;
use Sunchayn\Strata\Modules\Cache\ValueObjects\TaggableValue;
use Sunchayn\Strata\Tests\TestCase;

#[CoversClass(StrataTaggedCache::class)]
final class StrataTaggedCacheFunctionalTest extends TestCase
{
    public function test_it_asks_the_tags_manager_for_the_id_of_each_tag_when_created(): void
    {
        // Arrange

        $storeMock = $this->mock(StrataStore::class);

        $tagsManagerMock = $this->mock(TagsManager::class);

        // Anticipate

        $tagsManagerMock->expects('getIdOrCreate')->with('assets')->andReturn('id-1');

        $tagsManagerMock->expects('getIdOrCreate')->with('relations')->andReturn('id-2');

        // Act

        $this->createTaggedCacheInstance($storeMock, $tagsManagerMock, ['assets', 'relations']);
    }

    public function test_it_wraps_a_value_with_the_ids_captured_when_it_was_created(): void
    {
        // Arrange

        $storeMock = $this->mock(StrataStore::class);

        $tagsManagerMock = $this->mock(TagsManager::class);

        // Anticipate

        $tagsManagerMock->expects('getIdOrCreate')->with('assets')->andReturn('id-1');

        $storeMock->allows('put')->andReturnTrue();

        // Act

        $strataTaggedCache = $this->createTaggedCacheInstance($storeMock, $tagsManagerMock, ['assets']);

        $strataTaggedCache->put('books:1', 'one', 60);

        $strataTaggedCache->put('books:2', 'two', 60);

        // Assert

        $storeMock
            ->shouldHaveReceived('put')
            ->twice()
            ->withArgs(function (string $key, TaggableValue $value, int $seconds): bool {
                $this->assertSame(['assets' => 'id-1'], $value->tags);

                return true;
            });
    }

    public function test_it_tracks_a_repeated_tag_once(): void
    {
        // Arrange

        $storeMock = $this->mock(StrataStore::class);

        $tagsManagerMock = $this->mock(TagsManager::class);

        // Anticipate

        $tagsManagerMock->allows('getIdOrCreate')->with('assets')->andReturn('id-1');

        $storeMock->allows('put')->andReturnTrue();

        // Act

        $this->createTaggedCacheInstance($storeMock, $tagsManagerMock, ['assets', 'assets'])->put('books:1', 'value', 60);

        // Assert

        $storeMock
            ->shouldHaveReceived('put')
            ->withArgs(function (string $key, TaggableValue $value, int $seconds): bool {
                $this->assertSame(['assets' => 'id-1'], $value->tags);

                return true;
            });
    }

    public function test_it_wraps_a_value_with_an_empty_tag_list(): void
    {
        // Arrange

        $storeMock = $this->mock(StrataStore::class);

        $tagsManagerMock = $this->mock(TagsManager::class);

        // Anticipate

        $tagsManagerMock->shouldNotReceive('getIdOrCreate');

        $storeMock->allows('put')->andReturnTrue();

        // Act

        $this->createTaggedCacheInstance($storeMock, $tagsManagerMock, [])->put('books:1', 'value', 60);

        // Assert

        $storeMock
            ->shouldHaveReceived('put')
            ->withArgs(function (string $key, TaggableValue $value, int $seconds): bool {
                $this->assertSame('value', $value->unwrap());
                $this->assertSame([], $value->tags);

                return true;
            });
    }

    public function test_it_wraps_a_value_with_the_tag_ids_when_putting_it(): void
    {
        // Arrange

        $storeMock = $this->mock(StrataStore::class);
        $tagsManagerMock = $this->mock(TagsManager::class);

        // Anticipate

        $tagsManagerMock->expects('getIdOrCreate')->with('assets')->andReturn('id-1');
        $tagsManagerMock->expects('getIdOrCreate')->with('relations')->andReturn('id-2');

        $storeMock->allows('put')->andReturnTrue();

        // Act

        $written = $this->createTaggedCacheInstance($storeMock, $tagsManagerMock, ['assets', 'relations'])
            ->put('books:1', ['id' => 1], 60);

        // Assert

        $this->assertTrue($written);

        $storeMock
            ->shouldHaveReceived('put')
            ->withArgs(function (string $key, TaggableValue $value, int $seconds): bool {
                $this->assertSame('books:1', $key);
                $this->assertSame(['id' => 1], $value->unwrap());
                $this->assertSame(['assets' => 'id-1', 'relations' => 'id-2'], $value->tags);
                $this->assertSame(60, $seconds);

                return true;
            });
    }

    public function test_it_wraps_a_value_with_the_rotated_ids_when_putting_it_after_a_flush(): void
    {
        // Arrange

        $storeMock = $this->mock(StrataStore::class);

        $tagsManagerMock = $this->mock(TagsManager::class);

        // Anticipate

        $tagsManagerMock->expects('getIdOrCreate')->with('assets')->twice()->andReturn('id-1', 'id-2');

        $tagsManagerMock->expects('rotateId')->with('assets')->andReturn('id-2');

        $storeMock->allows('put')->andReturnTrue();

        $strataTaggedCache = $this->createTaggedCacheInstance($storeMock, $tagsManagerMock, ['assets']);

        // Act

        $strataTaggedCache->flush();

        $strataTaggedCache->put('books:1', 'value', 60);

        // Assert

        $storeMock
            ->shouldHaveReceived('put')
            ->withArgs(function (string $key, TaggableValue $value, int $seconds): bool {
                $this->assertSame(['assets' => 'id-2'], $value->tags);

                return true;
            });
    }

    #[DataProvider('writeResultProvider')]
    public function test_it_returns_the_result_of_the_write(bool $written): void
    {
        // Arrange

        $storeMock = $this->mock(StrataStore::class);

        $tagsManagerMock = $this->mock(TagsManager::class);

        // Anticipate

        $storeMock->expects('put')->andReturn($written);

        // Act

        $result = $this->createTaggedCacheInstance($storeMock, $tagsManagerMock, [])->put('books:1', 'value', 60);

        // Assert

        $this->assertSame($written, $result);
    }

    /**
     * @return Generator<string, array{written: bool}>
     */
    public static function writeResultProvider(): Generator
    {
        yield 'a successful write' => [
            'written' => true,
        ];

        yield 'a failed write' => [
            'written' => false,
        ];
    }

    #[DataProvider('foreverWriteMethodProvider')]
    public function test_it_wraps_the_value_once_when_storing_it_forever(string $method): void
    {
        // Arrange

        $storeMock = $this->mock(StrataStore::class);

        $tagsManagerMock = $this->mock(TagsManager::class);

        // Anticipate

        $tagsManagerMock->allows('getIdOrCreate')->with('assets')->andReturn('id-1');

        $storeMock->allows('forever')->andReturnTrue();

        // Act

        $written = $this->createTaggedCacheInstance($storeMock, $tagsManagerMock, ['assets'])->{$method}('books:1', ['id' => 1]);

        // Assert

        $this->assertTrue($written);

        $storeMock
            ->shouldHaveReceived('forever')
            ->withArgs(function (string $key, TaggableValue $value): bool {
                $this->assertSame('books:1', $key);
                $this->assertSame(['id' => 1], $value->unwrap());
                $this->assertSame(['assets' => 'id-1'], $value->tags);

                return true;
            });
    }

    /**
     * @return Generator<string, array{method: string}>
     */
    public static function foreverWriteMethodProvider(): Generator
    {
        yield 'storing it forever' => [
            'method' => 'forever',
        ];

        yield 'putting it without a ttl' => [
            'method' => 'put',
        ];
    }

    #[DataProvider('multipleWriteMethodProvider')]
    public function test_it_wraps_the_value_of_every_key_when_putting_many_values(string $method): void
    {
        // Arrange

        $storeMock = $this->mock(StrataStore::class);

        $tagsManagerMock = $this->mock(TagsManager::class);

        $expectedValues = ['books:1' => 'one', 'books:2' => 'two'];

        // Anticipate

        $tagsManagerMock->allows('getIdOrCreate')->with('assets')->andReturn('id-1');

        $storeMock->allows('put')->andReturnTrue();

        // Act

        $written = $this->createTaggedCacheInstance($storeMock, $tagsManagerMock, ['assets'])->{$method}($expectedValues, 60);

        // Assert

        $this->assertTrue($written);

        $storeMock
            ->shouldHaveReceived('put')
            ->twice()
            ->withArgs(function (string $key, TaggableValue $value, int $seconds) use ($expectedValues): bool {
                $this->assertSame($expectedValues[$key], $value->unwrap());
                $this->assertSame(['assets' => 'id-1'], $value->tags);
                $this->assertSame(60, $seconds);

                return true;
            });
    }

    /**
     * @return Generator<string, array{method: string}>
     */
    public static function multipleWriteMethodProvider(): Generator
    {
        yield 'putting many values' => [
            'method' => 'putMany',
        ];

        yield 'putting an array of keys' => [
            'method' => 'put',
        ];
    }

    public function test_it_wraps_the_value_of_every_key_when_putting_many_values_without_a_ttl(): void
    {
        // Arrange

        $storeMock = $this->mock(StrataStore::class);

        $tagsManagerMock = $this->mock(TagsManager::class);

        $expectedValues = ['books:1' => 'one', 'books:2' => 'two'];

        // Anticipate

        $tagsManagerMock->allows('getIdOrCreate')->with('assets')->andReturn('id-1');

        $storeMock->allows('forever')->andReturnTrue();

        // Act

        $written = $this->createTaggedCacheInstance($storeMock, $tagsManagerMock, ['assets'])->putMany($expectedValues);

        // Assert

        $this->assertTrue($written);

        $storeMock
            ->shouldHaveReceived('forever')
            ->twice()
            ->withArgs(function (string $key, TaggableValue $value) use ($expectedValues): bool {
                $this->assertSame($expectedValues[$key], $value->unwrap());
                $this->assertSame(['assets' => 'id-1'], $value->tags);

                return true;
            });
    }

    public function test_it_wraps_a_remembered_value(): void
    {
        // Arrange

        $storeMock = $this->mock(StrataStore::class);

        $tagsManagerMock = $this->mock(TagsManager::class);

        // Anticipate

        $tagsManagerMock->allows('getIdOrCreate')->with('assets')->andReturn('id-1');

        $storeMock->expects('get')->with('books:1')->andReturnNull();

        $storeMock->allows('put')->andReturnTrue();

        // Act

        $value = $this
            ->createTaggedCacheInstance($storeMock, $tagsManagerMock, ['assets'])
            ->remember('books:1', 60, fn (): string => 'computed');

        // Assert

        $this->assertSame('computed', $value);

        $storeMock
            ->shouldHaveReceived('put')
            ->withArgs(function (string $key, TaggableValue $value, int $seconds): bool {
                $this->assertSame('books:1', $key);
                $this->assertSame('computed', $value->unwrap());
                $this->assertSame(['assets' => 'id-1'], $value->tags);
                $this->assertSame(60, $seconds);

                return true;
            });
    }

    public function test_it_wraps_a_value_remembered_forever(): void
    {
        // Arrange

        $storeMock = $this->mock(StrataStore::class);

        $tagsManagerMock = $this->mock(TagsManager::class);

        // Anticipate

        $tagsManagerMock->allows('getIdOrCreate')->with('assets')->andReturn('id-1');

        $storeMock->expects('get')->with('books:1')->andReturnNull();

        $storeMock->allows('forever')->andReturnTrue();

        // Act

        $value = $this->createTaggedCacheInstance($storeMock, $tagsManagerMock, ['assets'])
            ->rememberForever('books:1', fn (): string => 'computed');

        // Assert

        $this->assertSame('computed', $value);

        $storeMock
            ->shouldHaveReceived('forever')
            ->withArgs(function (string $key, TaggableValue $value): bool {
                $this->assertSame('books:1', $key);
                $this->assertSame('computed', $value->unwrap());
                $this->assertSame(['assets' => 'id-1'], $value->tags);

                return true;
            });
    }

    public function test_it_wraps_an_added_value(): void
    {
        // Arrange

        $storeMock = $this->mock(StrataStore::class);

        $tagsManagerMock = $this->mock(TagsManager::class);

        // Anticipate

        $tagsManagerMock->allows('getIdOrCreate')->with('assets')->andReturn('id-1');

        $storeMock->allows('add')->andReturnTrue();

        // Act

        $added = $this->createTaggedCacheInstance($storeMock, $tagsManagerMock, ['assets'])->add('books:1', 'value', 60);

        // Assert

        $this->assertTrue($added);

        $storeMock
            ->shouldHaveReceived('add')
            ->withArgs(function (string $key, TaggableValue $value, int $seconds): bool {
                $this->assertSame('books:1', $key);
                $this->assertSame('value', $value->unwrap());
                $this->assertSame(['assets' => 'id-1'], $value->tags);
                $this->assertSame(60, $seconds);

                return true;
            });
    }

    public function test_it_reads_a_value_under_the_plain_untouched_key(): void
    {
        // Arrange

        $storeMock = $this->mock(StrataStore::class);
        $tagsManagerMock = $this->mock(TagsManager::class);

        // Anticipate

        $storeMock->expects('get')->with('books:1')->andReturn('value');

        // Act

        $value = $this->createTaggedCacheInstance($storeMock, $tagsManagerMock, [])->get('books:1');

        // Assert

        $this->assertSame('value', $value);
    }

    public function test_it_accepts_an_enum_as_a_key(): void
    {
        // Arrange

        $storeMock = $this->mock(StrataStore::class);

        $tagsManagerMock = $this->mock(TagsManager::class);

        // Anticipate

        $storeMock->allows('put')->andReturnTrue();

        // Act

        $this->createTaggedCacheInstance($storeMock, $tagsManagerMock, [])->put(CacheKeyStub::Books, 'value', 60);

        // Assert

        $storeMock
            ->shouldHaveReceived('put')
            ->withArgs(function (string $key, TaggableValue $value, int $seconds): bool {
                $this->assertSame('books', $key);

                return true;
            });
    }

    #[DataProvider('numericChangeMethodProvider')]
    public function test_it_forwards_a_numeric_change_to_the_store_under_the_plain_untouched_key(string $method): void
    {
        // Arrange

        $storeMock = $this->mock(StrataStore::class);

        $tagsManagerMock = $this->mock(TagsManager::class);

        // Anticipate

        $storeMock->expects($method)->with('counter', 3)->andReturn(8);

        // Act

        $result = $this->createTaggedCacheInstance($storeMock, $tagsManagerMock, [])->{$method}('counter', 3);

        // Assert

        $this->assertSame(8, $result);
    }

    /**
     * @return Generator<string, array{method: string}>
     */
    public static function numericChangeMethodProvider(): Generator
    {
        yield 'incrementing' => [
            'method' => 'increment',
        ];

        yield 'decrementing' => [
            'method' => 'decrement',
        ];
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    #[DataProvider('writeEventMethodProvider')]
    public function test_it_dispatches_write_events_with_the_original_value(string $method, string $storeMethod, array $arguments): void
    {
        // Arrange

        Event::fake([WritingKey::class, KeyWritten::class]);

        $storeMock = $this->mock(StrataStore::class);

        $tagsManagerMock = $this->mock(TagsManager::class);

        // Anticipate

        $tagsManagerMock->allows('getIdOrCreate')->with('assets')->andReturn('id-1');

        $storeMock->expects($storeMethod)->andReturnTrue();

        // Act

        $this->createTaggedCacheInstance($storeMock, $tagsManagerMock, ['assets'], dispatchEvents: true)->{$method}(...$arguments);

        // Assert

        Event::assertDispatched(
            WritingKey::class,
            fn (WritingKey $event): bool => $event->key === 'books:1' && $event->value === ['id' => 1],
        );

        Event::assertDispatched(
            KeyWritten::class,
            fn (KeyWritten $event): bool => $event->key === 'books:1' && $event->value === ['id' => 1],
        );
    }

    /**
     * @return Generator<string, array{method: string, storeMethod: string, arguments: array<int, mixed>}>
     */
    public static function writeEventMethodProvider(): Generator
    {
        yield 'putting a value' => [
            'method' => 'put',
            'storeMethod' => 'put',
            'arguments' => ['books:1', ['id' => 1], 60],
        ];

        yield 'storing a value forever' => [
            'method' => 'forever',
            'storeMethod' => 'forever',
            'arguments' => ['books:1', ['id' => 1]],
        ];
    }

    public function test_it_dispatches_a_write_failure_event_with_the_original_value(): void
    {
        // Arrange

        Event::fake([KeyWriteFailed::class]);

        $storeMock = $this->mock(StrataStore::class);

        $tagsManagerMock = $this->mock(TagsManager::class);

        // Anticipate

        $tagsManagerMock->allows('getIdOrCreate')->with('assets')->andReturn('id-1');

        $storeMock->expects('put')->andReturnFalse();

        // Act

        $this
            ->createTaggedCacheInstance($storeMock, $tagsManagerMock, ['assets'], dispatchEvents: true)
            ->put('books:1', ['id' => 1], 60);

        // Assert

        Event::assertDispatched(
            KeyWriteFailed::class,
            fn (KeyWriteFailed $event): bool => $event->value === ['id' => 1],
        );
    }

    public function test_it_leaves_the_value_of_a_write_event_untouched_when_it_is_not_wrapped(): void
    {
        // Arrange

        Event::fake([KeyWritten::class]);

        $strataTaggedCache = $this->createTaggedCacheInstance(
            $this->mock(StrataStore::class),
            $this->mock(TagsManager::class),
            [],
            dispatchEvents: true,
        );

        // Act

        $this->dispatchEvent($strataTaggedCache, new KeyWritten('strata', 'books:1', ['id' => 1], 60));

        // Assert

        Event::assertDispatched(KeyWritten::class, fn (KeyWritten $event): bool => $event->value === ['id' => 1]);
    }

    public function test_it_unwraps_the_value_of_a_write_event_that_is_wrapped(): void
    {
        // Arrange

        Event::fake([KeyWritten::class]);

        $strataTaggedCache = $this->createTaggedCacheInstance(
            $this->mock(StrataStore::class),
            $this->mock(TagsManager::class),
            [],
            dispatchEvents: true,
        );

        // Act

        $this->dispatchEvent(
            $strataTaggedCache,
            new KeyWritten('strata', 'books:1', new TaggableValue('value', ['assets' => 'id-1']), 60),
        );

        // Assert

        Event::assertDispatched(KeyWritten::class, fn (KeyWritten $event): bool => $event->value === 'value');
    }

    public function test_it_dispatches_a_plain_string_event_as_it_is(): void
    {
        // Arrange

        Event::fake(['strata.custom-event']);

        $strataTaggedCache = $this->createTaggedCacheInstance(
            $this->mock(StrataStore::class),
            $this->mock(TagsManager::class),
            [],
            dispatchEvents: true,
        );

        // Act

        $this->dispatchEvent($strataTaggedCache, 'strata.custom-event');

        // Assert

        Event::assertDispatched('strata.custom-event');
    }

    public function test_it_works_without_an_event_dispatcher(): void
    {
        // Arrange

        $storeMock = $this->mock(StrataStore::class);
        $tagsManagerMock = $this->mock(TagsManager::class);

        // Anticipate

        $storeMock->expects('put')->andReturnTrue();

        // Act

        $written = $this->createTaggedCacheInstance($storeMock, $tagsManagerMock, [])->put('books:1', 'value', 60);

        // Assert

        $this->assertTrue($written);
    }

    /**
     * @param  array<int, string>  $names
     */
    private function createTaggedCacheInstance(
        StrataStore $store,
        TagsManager $tagsManager,
        array $names,
        bool $dispatchEvents = false,
    ): StrataTaggedCache {
        $strataTagSet = resolve(StrataTagSet::class, [
            'store' => $store,
            'tagsManager' => $tagsManager,
            'names' => $names,
        ]);

        $strataTaggedCache = resolve(StrataTaggedCache::class, [
            'tagStore' => $store,
            'tags' => $strataTagSet,
        ]);

        if ($dispatchEvents) {
            $strataTaggedCache->setEventDispatcher(resolve('events'));
        }

        return $strataTaggedCache;
    }

    /**
     * @throws \ReflectionException
     */
    private function dispatchEvent(StrataTaggedCache $cache, object|string $event): void
    {
        (new ReflectionMethod($cache, 'event'))->invoke($cache, $event);
    }
}

enum CacheKeyStub: string
{
    case Books = 'books';
}
