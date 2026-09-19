<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Tests\App\Modules\Cache;

use Generator;
use Illuminate\Contracts\Cache\CanFlushLocks;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Sunchayn\Strata\Modules\Cache\Contracts\SafeLockProvider;
use Sunchayn\Strata\Modules\Cache\Exceptions\InvalidTagNameException;
use Sunchayn\Strata\Modules\Cache\Services\TagsManager;
use Sunchayn\Strata\Modules\Cache\StrataStore;
use Sunchayn\Strata\Modules\Cache\ValueObjects\TaggableValue;
use Sunchayn\Strata\Tests\TestCase;

#[CoversClass(StrataStore::class)]
final class StrataStoreFunctionalTest extends TestCase
{
    #[DataProvider('valueProvider')]
    public function test_it_returns_the_value_that_was_put_with_its_original_type(mixed $value): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        // Act

        $strataStore->put('key', $value, 60);

        // Assert

        $this->assertEquals($value, $strataStore->get('key'));

        $this->assertSame(gettype($value), gettype($strataStore->get('key')));
    }

    /**
     * @return Generator<string, array{value: mixed}>
     */
    public static function valueProvider(): Generator
    {
        yield 'string' => [
            'value' => 'books',
        ];

        yield 'empty string' => [
            'value' => '',
        ];

        yield 'integer' => [
            'value' => 42,
        ];

        yield 'zero' => [
            'value' => 0,
        ];

        yield 'float' => [
            'value' => 1.5,
        ];

        yield 'true' => [
            'value' => true,
        ];

        yield 'false' => [
            'value' => false,
        ];

        yield 'array' => [
            'value' => ['id' => 1, 'nested' => ['a', 'b']],
        ];

        yield 'empty array' => [
            'value' => [],
        ];

        yield 'object' => [
            'value' => (object) ['id' => 1],
        ];
    }

    public function test_it_stores_the_expiry_an_empty_tags_line_and_the_serialized_value(): void
    {
        // Arrange

        $this->freezeTime();

        $strataStore = resolve(StrataStore::class);

        // Act

        $written = $strataStore->put('key', 'value', 60);

        // Assert

        $this->assertTrue($written);

        $this->assertSame(
            (now()->getTimestamp() + 60)."\n\n".serialize('value'),
            file_get_contents($this->cachedValueFilepath('key')),
        );
    }

    public function test_it_stores_the_tags_as_json_on_the_second_line(): void
    {
        // Arrange

        $this->freezeTime();

        $strataStore = resolve(StrataStore::class);

        // Act

        $strataStore->put('key', new TaggableValue('value', ['assets' => 'id-1', 'relations' => 'id-2']), 60);

        // Assert

        $this->assertSame(
            (now()->getTimestamp() + 60)."\n".'{"assets":"id-1","relations":"id-2"}'."\n".serialize('value'),
            file_get_contents($this->cachedValueFilepath('key')),
        );
    }

    public function test_it_reads_back_a_value_that_contains_new_lines(): void
    {
        // Arrange

        $tagsManagerMock = $this->mock(TagsManager::class);

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('string', "first\n\nsecond\n", 60);
        $strataStore->put('tagged', new TaggableValue(['a' => "x\ny"], ['assets' => 'id-1']), 60);

        // Anticipate

        $tagsManagerMock->allows('getId')->with('assets')->andReturn('id-1');

        // Act

        $string = $strataStore->get('string');
        $tagged = $strataStore->get('tagged');

        // Assert

        $this->assertSame("first\n\nsecond\n", $string);

        $this->assertSame(['a' => "x\ny"], $tagged);
    }

    public function test_it_reads_a_tag_with_a_numeric_name(): void
    {
        // Arrange

        $tagsManagerMock = $this->mock(TagsManager::class);

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('key', new TaggableValue('value', ['2024' => 'id-1']), 60);

        // Anticipate

        $tagsManagerMock->expects('getId')->with('2024')->andReturn('id-1');

        // Act

        $value = $strataStore->get('key');

        // Assert

        $this->assertSame('value', $value);
    }

    public function test_it_does_not_unserialize_an_expired_value(): void
    {
        // Arrange

        UnserializeValue::$unserialized = false;

        $strataStore = resolve(StrataStore::class);

        file_put_contents($this->createDummyCachedValue('key'), (now()->getTimestamp() - 1)."\n\n".serialize(new UnserializeValue));

        // Act

        $value = $strataStore->get('key');

        // Assert

        $this->assertNull($value);

        $this->assertFalse(UnserializeValue::$unserialized);

        $this->assertSame([], $this->cachedValuesFilePaths());
    }

    public function test_it_does_not_unserialize_a_value_whose_tag_was_flushed(): void
    {
        // Arrange

        UnserializeValue::$unserialized = false;

        $tagsManagerMock = $this->mock(TagsManager::class);

        $strataStore = resolve(StrataStore::class);

        file_put_contents(
            $this->createDummyCachedValue('key'),
            (now()->getTimestamp() + 60)."\n".'{"assets":"id-1"}'."\n".serialize(new UnserializeValue),
        );

        // Anticipate

        $tagsManagerMock->expects('getId')->with('assets')->andReturn('id-2');

        // Act

        $value = $strataStore->get('key');

        // Assert

        $this->assertNull($value);

        $this->assertFalse(UnserializeValue::$unserialized);

        $this->assertSame([], $this->cachedValuesFilePaths());
    }

    public function test_it_unserializes_a_live_value(): void
    {
        // Arrange

        UnserializeValue::$unserialized = false;

        $strataStore = resolve(StrataStore::class);

        file_put_contents($this->createDummyCachedValue('key'), (now()->getTimestamp() + 60)."\n\n".serialize(new UnserializeValue));

        // Act

        $value = $strataStore->get('key');

        // Assert

        $this->assertInstanceOf(UnserializeValue::class, $value);

        $this->assertTrue(UnserializeValue::$unserialized);
    }

    public function test_it_adds_over_an_expired_file_without_unserializing_its_value(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        file_put_contents($this->createDummyCachedValue('key'), (now()->getTimestamp() - 1)."\n\ngarbage");

        // Act

        $added = $strataStore->add('key', 'value', 60);

        // Assert

        $this->assertTrue($added);

        $this->assertSame('value', $strataStore->get('key'));
    }

    public function test_it_stores_the_tags_with_their_ids_in_the_payload_of_a_tagged_value(): void
    {
        // Arrange

        $this->freezeTime();

        $strataStore = resolve(StrataStore::class);

        // Act

        $strataStore->put('key', new TaggableValue('value', ['assets' => 'id-1', 'relations' => 'id-2']), 60);

        // Assert

        $this->assertSame(
            [
                'value' => 'value',
                'expires_at' => now()->getTimestamp() + 60,
                'tags' => ['assets' => 'id-1', 'relations' => 'id-2'],
            ],
            $this->cachedValuePayload('key'),
        );
    }

    public function test_it_overwrites_the_value_and_replaces_the_expiry(): void
    {
        // Arrange

        $this->freezeTime();

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('key', 'old', 10);

        $strataStore->put('key', 'new', 100);

        $expectedExpiry = now()->getTimestamp() + 100;

        // Act

        $storedPayload = $this->cachedValuePayload('key');

        $storedFilepaths = $this->cachedValuesFilePaths();

        $this->travel(10)->seconds();
        $afterOldExpiry = $strataStore->get('key');

        $this->travel(89)->seconds(); // <- Now at second 99
        $beforeNewExpiry = $strataStore->get('key');

        $this->travel(1)->seconds(); // <- Now at second 100
        $atNewExpiry = $strataStore->get('key');

        // Assert

        $this->assertSame(['value' => 'new', 'expires_at' => $expectedExpiry], $storedPayload);

        $this->assertSame([$this->cachedValueFilepath('key')], $storedFilepaths);

        $this->assertSame('new', $afterOldExpiry);
        $this->assertSame('new', $beforeNewExpiry);
        $this->assertNull($atNewExpiry);
    }

    #[DataProvider('unsafeKeyProvider')]
    public function test_it_stores_any_key_as_a_hashed_file_inside_the_data_directory(string $key): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        // Act

        $strataStore->put($key, 'value', 60);

        // Assert

        $hash = sha1($key);

        $this->assertSame('value', $strataStore->get($key));

        $this->assertSame(
            [$this->cacheDirectory.'/data/'.substr($hash, 0, 2).'/'.substr($hash, 2, 2).'/'.$hash],
            $this->cachedValuesFilePaths(),
        );
    }

    /**
     * @return Generator<string, array{key: string}>
     */
    public static function unsafeKeyProvider(): Generator
    {
        yield 'with a slash' => [
            'key' => 'users/1',
        ];

        yield 'with a parent directory segment' => [
            'key' => '../../escape',
        ];

        yield 'with spaces' => [
            'key' => 'my key',
        ];

        yield 'with unicode' => [
            'key' => 'clé',
        ];

        yield 'with a very long name' => [
            'key' => str_repeat('a', 500),
        ];

        yield 'numeric' => [
            'key' => '123',
        ];
    }

    public function test_it_writes_cached_files_with_the_configured_file_permission(): void
    {
        // Arrange

        config(['strata.file_permission' => $permission = 0o750]);

        $strataStore = resolve(StrataStore::class);

        // Act

        $strataStore->put('key', 'value', 60);

        // Assert

        $file = $this->cachedValuesFilePaths()[0];

        $this->assertSame($permission, $this->permissionsOf($file));

        $this->assertSame($permission, $this->permissionsOf(dirname($file)));

        $this->assertSame($permission, $this->permissionsOf(dirname($file, 2)));
    }

    public function test_it_treats_a_negative_ttl_as_already_expired(): void
    {
        // Arrange

        $this->freezeTime();

        $strataStore = resolve(StrataStore::class);

        // Act

        $strataStore->put('key', 'value', -1);

        // Assert

        $this->assertSame(now()->getTimestamp() - 1, $this->cachedValuePayload('key')['expires_at']);

        $this->assertNull($strataStore->get('key'));

        $this->assertSame([], $this->cachedValuesFilePaths());
    }

    public function test_it_deletes_a_value_put_with_a_zero_ttl_through_the_cache_repository(): void
    {
        // Arrange

        $repository = Cache::store('strata');

        $repository->put('key', 'value', 60);

        // Act

        $repository->put('key', 'other', 0);

        // Assert

        $this->assertNull($repository->get('key'));

        $this->assertSame([], $this->cachedValuesFilePaths());
    }

    public function test_it_caps_a_ttl_that_goes_beyond_the_supported_date_range(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        // Act

        $strataStore->put('key', 'value', StrataStore::FOREVER_TTL);

        // Assert

        $this->assertSame(StrataStore::FOREVER_TTL, $this->cachedValuePayload('key')['expires_at']);

        $this->assertSame('value', $strataStore->get('key'));
    }

    public function test_it_returns_null_for_a_value_that_was_put_as_null(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        // Act

        $strataStore->put('key', null, 60);

        // Assert

        $this->assertNull($strataStore->get('key'));
    }

    public function test_it_reports_a_failed_write(): void
    {
        // Arrange

        $filesystemMock = $this->mock(Filesystem::class);

        // Anticipate

        $filesystemMock->allows('exists')->andReturn(true);
        $filesystemMock->expects('put')->andReturn(false);
        $filesystemMock->shouldNotReceive('chmod');

        $strataStore = resolve(StrataStore::class);

        // Act

        $written = $strataStore->put('key', 'value', 60);

        // Assert

        $this->assertFalse($written);
    }

    public function test_it_stores_many_values_at_once(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        // Act

        $result = $strataStore->putMany(['books:1' => 'one', 'books:2' => 'two'], 60);

        // Assert

        $this->assertTrue($result);

        $this->assertSame('one', $strataStore->get('books:1'));

        $this->assertSame('two', $strataStore->get('books:2'));

        $this->assertEqualsCanonicalizing(
            [$this->cachedValueFilepath('books:1'), $this->cachedValueFilepath('books:2')],
            $this->cachedValuesFilePaths(),
        );
    }

    public function test_it_applies_the_same_ttl_to_every_value_stored_at_once(): void
    {
        // Arrange

        $this->freezeTime();

        $strataStore = resolve(StrataStore::class);

        $strataStore->putMany(['books:1' => 'one', 'books:2' => 'two'], 60);

        $expectedExpiry = now()->getTimestamp() + 60;

        // Act

        $storedExpires = [
            $this->cachedValuePayload('books:1')['expires_at'],
            $this->cachedValuePayload('books:2')['expires_at'],
        ];

        $this->travel(59)->seconds();
        $before = [$strataStore->get('books:1'), $strataStore->get('books:2')];

        $this->travel(1)->seconds();
        $after = [$strataStore->get('books:1'), $strataStore->get('books:2')];

        // Assert

        $this->assertSame([$expectedExpiry, $expectedExpiry], $storedExpires);

        $this->assertSame(['one', 'two'], $before);

        $this->assertSame([null, null], $after);
    }

    public function test_it_stores_nothing_when_given_no_values(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        // Act & Assert

        $this->assertTrue($strataStore->putMany([], 60));

        $this->assertSame([], $this->cachedValuesFilePaths());
    }

    public function test_it_reports_a_failed_write_when_storing_many_values(): void
    {
        // Arrange

        $filesystemMock = $this->mock(Filesystem::class);

        // Anticipate

        $filesystemMock->allows('exists')->andReturn(true);
        $filesystemMock->allows('put')->andReturn(false);

        $strataStore = resolve(StrataStore::class);

        // Act & Assert

        $this->assertFalse($strataStore->putMany(['books:1' => 'one', 'books:2' => 'two'], 60));
    }

    public function test_it_keeps_writing_and_reports_a_failure_when_one_of_many_values_fails(): void
    {
        // Arrange

        $filesystemMock = $this->mock(Filesystem::class);

        // Anticipate

        $filesystemMock->allows('exists')->andReturn(true);
        $filesystemMock->allows('chmod')->andReturn('0755');
        $filesystemMock->expects('put')->twice()->andReturn(false, 10);

        $strataStore = resolve(StrataStore::class);

        // Act

        $result = $strataStore->putMany(['books:1' => 'one', 'books:2' => 'two'], 60);

        // Assert

        $this->assertFalse($result);
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    #[DataProvider('foreverStorageProvider')]
    public function test_it_keeps_a_value_forever(string $method, array $arguments): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        // Act

        $strataStore->{$method}(...$arguments);

        $this->travel(30)->years();

        // Assert

        $this->assertSame(StrataStore::FOREVER_TTL, $this->cachedValuePayload('key')['expires_at']);

        $this->assertSame('value', $strataStore->get('key'));
    }

    /**
     * @return Generator<string, array{method: string, arguments: array<int, mixed>}>
     */
    public static function foreverStorageProvider(): Generator
    {
        yield 'storing it forever' => [
            'method' => 'forever',
            'arguments' => ['key', 'value'],
        ];

        yield 'a zero ttl' => [
            'method' => 'put',
            'arguments' => ['key', 'value', 0],
        ];
    }

    public function test_it_expires_a_value_once_its_ttl_has_elapsed(): void
    {
        // Arrange

        $this->freezeTime();

        $strataStore = resolve(StrataStore::class);
        $strataStore->put('key', 'value', 60);

        // Act

        $this->travel(59)->seconds();
        $beforeExpiry = $strataStore->get('key');

        $this->travel(1)->seconds();
        $atExpiry = $strataStore->get('key');

        // Assert

        $this->assertSame('value', $beforeExpiry);

        $this->assertNull($atExpiry);

        $this->assertSame([], $this->cachedValuesFilePaths()); // <- it also get removed from the filesystem.
    }

    public function test_it_returns_null_for_a_missing_key(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        // Act & Assert

        $this->assertNull($strataStore->get('missing-key'));
    }

    #[DataProvider('corruptedPayloadProvider')]
    public function test_it_treats_a_corrupted_payload_as_missing_and_deletes_it(string $contents): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('key', 'value', 60);

        file_put_contents($this->cachedValuesFilePaths()[0], $contents);

        // Act

        $value = $strataStore->get('key');

        // Assert

        $this->assertNull($value);

        $this->assertSame([], $this->cachedValuesFilePaths());
    }

    /**
     * @return Generator<string, array{contents: string}>
     */
    public static function corruptedPayloadProvider(): Generator
    {
        yield 'empty file' => [
            'contents' => '',
        ];

        yield 'plain text' => [
            'contents' => 'not serialized',
        ];

        yield 'truncated value' => [
            'contents' => StrataStore::FOREVER_TTL."\n\n".substr(serialize('a long value'), 0, 8),
        ];

        yield 'old format payload' => [
            'contents' => serialize(['value' => 'v', 'expires_at' => StrataStore::FOREVER_TTL]),
        ];

        yield 'serialized value without a header' => [
            'contents' => serialize('value'),
        ];

        yield 'expiry without a tags line' => [
            'contents' => (string) StrataStore::FOREVER_TTL,
        ];

        yield 'non numeric expiry' => [
            'contents' => "soon\n\n".serialize('value'),
        ];

        yield 'invalid tags json' => [
            'contents' => StrataStore::FOREVER_TTL."\nnot json\n".serialize('value'),
        ];

        yield 'header without a value' => [
            'contents' => StrataStore::FOREVER_TTL."\n\n",
        ];
    }

    public function test_it_reads_many_values_keyed_by_the_requested_keys(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('books:1', 'one', 60);
        $strataStore->put('books:2', 'two', 60);

        // Act

        $values = $strataStore->many(['books:2', 'books:1']);

        // Assert

        $this->assertSame(['books:2' => 'two', 'books:1' => 'one'], $values);
    }

    public function test_it_returns_null_for_the_missing_keys_when_reading_many(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('books:1', 'one', 60);

        // Act

        $values = $strataStore->many(['books:1', 'books:2']);

        // Assert

        $this->assertSame(['books:1' => 'one', 'books:2' => null], $values);

        $this->assertSame([$this->cachedValueFilepath('books:1')], $this->cachedValuesFilePaths());
    }

    public function test_it_reads_nothing_when_given_no_keys(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        // Act & Assert

        $this->assertSame([], $strataStore->many([]));
    }

    #[DataProvider('numericChangeProvider')]
    public function test_it_changes_a_numeric_value_by_the_given_amount(
        string $method,
        mixed $initial,
        array $arguments,
        int $expected,
    ): void {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('counter', $initial, 60);

        // Act

        $newValue = $strataStore->{$method}('counter', ...$arguments);

        // Assert

        $this->assertSame($expected, $newValue);

        $this->assertSame($expected, $strataStore->get('counter'));

        $this->assertSame([$this->cachedValueFilepath('counter')], $this->cachedValuesFilePaths());
    }

    /**
     * @return Generator<string, array{method: string, initial: mixed, arguments: array<int, int>, expected: int}>
     */
    public static function numericChangeProvider(): Generator
    {
        yield 'increments by one by default' => [
            'method' => 'increment',
            'initial' => 5,
            'arguments' => [],
            'expected' => 6,
        ];

        yield 'increments by a given amount' => [
            'method' => 'increment',
            'initial' => 5,
            'arguments' => [10],
            'expected' => 15,
        ];

        yield 'increments a numeric string' => [
            'method' => 'increment',
            'initial' => '5',
            'arguments' => [],
            'expected' => 6,
        ];

        yield 'decrements by one by default' => [
            'method' => 'decrement',
            'initial' => 5,
            'arguments' => [],
            'expected' => 4,
        ];

        yield 'decrements by a given amount' => [
            'method' => 'decrement',
            'initial' => 5,
            'arguments' => [2],
            'expected' => 3,
        ];

        yield 'decrements below zero' => [
            'method' => 'decrement',
            'initial' => 1,
            'arguments' => [3],
            'expected' => -2,
        ];
    }

    #[DataProvider('unaryMethodsProvider')]
    public function test_it_keeps_the_original_expiry_when_changing_a_value(string $method, int $coefficient): void
    {
        // Arrange

        $this->freezeTime();

        $strataStore = resolve(StrataStore::class);
        $strataStore->put('counter', $initial = 5, 60);

        $expectedExpiry = now()->getTimestamp() + 60;

        $this->travel(30)->seconds();

        // Act

        $strataStore->{$method}('counter');

        $storedPayload = $this->cachedValuePayload('counter');

        $this->travel(29)->seconds();
        $beforeExpiry = $strataStore->get('counter');

        $this->travel(1)->seconds();
        $atExpiry = $strataStore->get('counter');

        // Assert

        $expected = $initial + $coefficient;

        $this->assertSame(
            ['value' => $expected, 'expires_at' => $expectedExpiry],
            $storedPayload,
        );

        $this->assertSame($expected, $beforeExpiry);

        $this->assertNull($atExpiry);
    }

    #[DataProvider('unaryMethodsProvider')]
    public function test_it_keeps_a_forever_value_forever_when_changing_it(string $method, int $coefficient): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        $strataStore->forever('counter', $initial = 5);

        // Act

        $strataStore->{$method}('counter');

        $this->travel(30)->years();

        // Assert

        $expected = $initial + $coefficient;

        $this->assertSame(
            ['value' => $expected, 'expires_at' => StrataStore::FOREVER_TTL],
            $this->cachedValuePayload('counter'),
        );

        $this->assertSame($expected, $strataStore->get('counter'));
    }

    #[DataProvider('unaryMethodsProvider')]
    public function test_it_keeps_the_tags_of_a_value_when_changing_it(string $method, int $coefficient): void
    {
        // Arrange

        $tagsManagerMock = $this->mock(TagsManager::class);

        $this->freezeTime();

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('counter', new TaggableValue($initial = 5, ['assets' => 'id-1']), 60);

        // Anticipate

        $tagsManagerMock->allows('getId')->with('assets')->andReturn('id-1');

        // Act

        $strataStore->{$method}('counter');

        // Assert

        $this->assertSame(
            [
                'value' => $initial + $coefficient,
                'expires_at' => now()->getTimestamp() + 60,
                'tags' => ['assets' => 'id-1'],
            ],
            $this->cachedValuePayload('counter'),
        );
    }

    #[DataProvider('unaryMethodsProvider')]
    public function test_it_creates_a_missing_key_when_changing_it(string $method, int $coefficient): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        // Act

        $newValue = $strataStore->{$method}('counter');

        // Assert

        $this->assertSame($coefficient, $newValue);

        $this->assertSame($coefficient, $strataStore->get('counter'));

        $this->assertSame(
            ['value' => $coefficient, 'expires_at' => StrataStore::FOREVER_TTL],
            $this->cachedValuePayload('counter'),
        );
    }

    #[DataProvider('unaryMethodsProvider')]
    public function test_it_recreates_an_expired_key_when_changing_it(string $method, int $coefficient): void
    {
        // Arrange

        $this->freezeTime();

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('counter', 5, 60);

        $this->travel(60)->seconds();

        // Act

        $newValue = $strataStore->{$method}('counter');

        // Assert

        $this->assertSame($coefficient, $newValue);

        $this->assertSame(
            ['value' => $coefficient, 'expires_at' => StrataStore::FOREVER_TTL],
            $this->cachedValuePayload('counter'),
        );
    }

    public function test_it_creates_a_missing_key_with_the_given_amount(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        // Act & Assert

        $this->assertSame(5, $strataStore->increment('up', 5));

        $this->assertSame(-5, $strataStore->decrement('down', 5));
    }

    #[DataProvider('corruptedPayloadProvider')]
    public function test_it_increments_over_a_corrupted_value_as_if_it_were_missing(string $contents): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('key', 'value', 60);

        file_put_contents($this->cachedValuesFilePaths()[0], $contents);

        // Act

        $newValue = $strataStore->increment('key', 5);

        // Assert

        $this->assertSame(5, $newValue);

        $this->assertSame(5, $strataStore->get('key'));
    }

    public function test_it_is_atomic_under_concurrent_increments(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('counter', 0, 60);

        $workers = 8;

        $incrementsPerWorker = 50;

        $processes = $this->startWorkersInLockStep(
            command: $this->incrementCounterCommand($incrementsPerWorker),
            workers: $workers,
        );

        foreach ($processes as $process) {
            proc_close($process);
        }

        // Act

        $finalValue = $strataStore->get('counter');

        // Assert

        $this->assertSame($workers * $incrementsPerWorker, $finalValue);
    }

    /**
     * @return Generator<string, array{method: string, coefficient: int}>
     */
    public static function unaryMethodsProvider(): Generator
    {
        yield 'incrementing' => [
            'method' => 'increment',
            'coefficient' => 1,
        ];

        yield 'decrementing' => [
            'method' => 'decrement',
            'coefficient' => -1,
        ];
    }

    public function test_it_touches_a_value_to_extend_its_ttl(): void
    {
        // Arrange

        $this->freezeTime();

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('key', 'value', 60);

        $this->travel(50)->seconds();

        $expectedExpiry = now()->getTimestamp() + 60;

        // Act

        $touched = $strataStore->touch('key', 60);

        $storedPayload = $this->cachedValuePayload('key');

        $this->travel(59)->seconds();

        // Assert

        $this->assertTrue($touched);

        $this->assertSame(['value' => 'value', 'expires_at' => $expectedExpiry], $storedPayload);

        $this->assertSame('value', $strataStore->get('key'));
    }

    public function test_it_touches_a_value_to_shorten_its_ttl(): void
    {
        // Arrange

        $this->freezeTime();

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('key', 'value', 600);

        $expectedExpiry = now()->getTimestamp() + 10;

        // Act

        $strataStore->touch('key', 10);

        $storedPayload = $this->cachedValuePayload('key');

        $this->travel(9)->seconds();
        $before = $strataStore->get('key');

        $this->travel(1)->seconds();
        $after = $strataStore->get('key');

        // Assert

        $this->assertSame(['value' => 'value', 'expires_at' => $expectedExpiry], $storedPayload);

        $this->assertSame('value', $before);

        $this->assertNull($after);
    }

    public function test_it_keeps_the_tags_of_a_value_when_touching(): void
    {
        // Arrange

        $tagsManagerMock = $this->mock(TagsManager::class);

        $this->freezeTime();

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('key', new TaggableValue('value', ['assets' => 'id-1']), 60);

        // Anticipate

        $tagsManagerMock->allows('getId')->with('assets')->andReturn('id-1');

        // Act

        $strataStore->touch('key', 120);

        // Assert

        $this->assertSame(
            [
                'value' => 'value',
                'expires_at' => now()->getTimestamp() + 120,
                'tags' => ['assets' => 'id-1'],
            ],
            $this->cachedValuePayload('key'),
        );
    }

    public function test_it_does_not_touch_a_missing_key(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        // Act & Assert

        $this->assertFalse($strataStore->touch('key', 60));

        $this->assertNull($strataStore->get('key'));
    }

    public function test_it_does_not_touch_an_expired_key(): void
    {
        // Arrange

        $this->freezeTime();

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('key', 'value', 60);

        $this->travel(60)->seconds();

        // Act & Assert

        $this->assertFalse($strataStore->touch('key', 60));
    }

    public function test_it_reports_a_failed_write_when_touching(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('key', 'value', 60);

        $filesystemMock = $this->mock(Filesystem::class);

        // Anticipate

        $filesystemMock->allows('exists')->andReturn(true);
        $filesystemMock->allows('get')->andReturn(file_get_contents($this->cachedValuesFilePaths()[0]));
        $filesystemMock->allows('put')->andReturn(false);

        $this->app->forgetInstance(StrataStore::class);

        // Act & Assert

        $this->assertFalse(resolve(StrataStore::class)->touch('key', 120));
    }

    public function test_it_forgets_only_the_requested_key(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('books:1', 'one', 60);

        $strataStore->put('books:2', 'two', 60);

        // Act

        $forgotten = $strataStore->forget('books:1');

        // Assert

        $this->assertTrue($forgotten);

        $this->assertNull($strataStore->get('books:1'));

        $this->assertSame('two', $strataStore->get('books:2'));

        $this->assertSame([$this->cachedValueFilepath('books:2')], $this->cachedValuesFilePaths());
    }

    public function test_it_forgets_a_tagged_value_without_touching_its_tag(): void
    {
        // Arrange

        $tagsManagerMock = $this->mock(TagsManager::class);

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('key', new TaggableValue('value', ['assets' => 'id-1']), 60);

        // Anticipate

        $tagsManagerMock->shouldNotReceive('rotateId', 'delete', 'deleteAll');

        // Act

        $forgotten = $strataStore->forget('key');

        // Assert

        $this->assertTrue($forgotten);

        $this->assertSame([], $this->cachedValuesFilePaths());
    }

    public function test_it_also_forgets_the_flexible_cache_created_at_sidecar_key(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('books:1', 'one', 60);

        $strataStore->put(StrataStore::FLEXIBLE_CREATED_KEY_PREFIX.'books:1', time(), 60);

        // Act

        $forgotten = $strataStore->forget('books:1');

        // Assert

        $this->assertTrue($forgotten);

        $this->assertNull($strataStore->get('books:1'));

        $this->assertNull($strataStore->get(StrataStore::FLEXIBLE_CREATED_KEY_PREFIX.'books:1'));
    }

    public function test_it_reports_forgetting_a_missing_key(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        // Act & Assert

        $this->assertFalse($strataStore->forget('missing-key'));
    }

    public function test_it_flushes_every_value(): void
    {
        // Arrange

        $tagsManagerMock = $this->mock(TagsManager::class);

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('books:1', 'one', 60);

        $strataStore->forever('books:2', 'two');

        // Anticipate

        $tagsManagerMock->expects('deleteAll'); // <- the tags are flushed along with the values.

        // Act

        $flushed = $strataStore->flush();

        // Assert

        $this->assertTrue($flushed);

        $this->assertNull($strataStore->get('books:1'));
        $this->assertNull($strataStore->get('books:2'));

        $this->assertSame([], $this->cachedValuesFilePaths());
    }

    public function test_it_reports_flushing_when_nothing_was_ever_stored(): void
    {
        // Arrange

        $tagsManagerMock = $this->mock(TagsManager::class);

        $strataStore = resolve(StrataStore::class);

        // Anticipate

        $tagsManagerMock->shouldNotReceive('deleteAll');

        // Act & Assert

        $this->assertFalse($strataStore->flush());
    }

    public function test_it_invalidates_a_value_written_with_a_tagged_value_once_the_tag_rotates(): void
    {
        // Arrange

        $tagsManagerMock = $this->mock(TagsManager::class);

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('key', new TaggableValue('value', ['assets' => 'id-1']), 60);

        // Anticipate

        $tagsManagerMock->expects('getId')->with('assets')->twice()->andReturn('id-1', 'id-2');

        // Act

        $beforeRotation = $strataStore->get('key');

        $afterRotation = $strataStore->get('key');

        // Assert

        $this->assertSame('value', $beforeRotation);

        $this->assertNull($afterRotation);
    }

    public function test_it_invalidates_a_tagged_value_once_its_tag_is_deleted(): void
    {
        // Arrange

        $tagsManagerMock = $this->mock(TagsManager::class);

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('key', new TaggableValue('value', ['assets' => 'id-1']), 60);

        // Anticipate

        $tagsManagerMock->expects('getId')->with('assets')->andReturnNull();

        // Act

        $value = $strataStore->get('key');

        // Assert

        $this->assertNull($value);

        $this->assertSame([], $this->cachedValuesFilePaths());
    }

    public function test_it_only_checks_the_tags_of_the_value(): void
    {
        // Arrange

        $tagsManagerMock = $this->mock(TagsManager::class);

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('key', new TaggableValue('value', ['assets' => 'id-1']), 60);

        // Anticipate

        $tagsManagerMock->expects('getId')->with('assets')->andReturn('id-1');

        $tagsManagerMock->shouldNotReceive('getId')->with('relations');

        // Act & Assert

        $this->assertSame('value', $strataStore->get('key'));
    }

    public function test_it_does_not_check_any_tag_for_an_untagged_value(): void
    {
        // Arrange

        $tagsManagerMock = $this->mock(TagsManager::class);

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('key', 'value', 60);

        // Anticipate

        $tagsManagerMock->shouldNotReceive('getId');

        // Act & Assert

        $this->assertSame('value', $strataStore->get('key'));
    }

    public function test_it_invalidates_a_value_when_either_of_its_tags_is_rotated(): void
    {
        // Arrange

        $tagsManagerMock = $this->mock(TagsManager::class);

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('key', new TaggableValue('value', ['assets' => 'id-1', 'relations' => 'id-2']), 60);

        // Anticipate

        $tagsManagerMock->expects('getId')->with('assets')->andReturn('id-1');

        $tagsManagerMock->expects('getId')->with('relations')->andReturn('id-3');

        // Act & Assert

        $this->assertNull($strataStore->get('key'));
    }

    public function test_it_accepts_tags_as_separate_arguments_or_as_an_array(): void
    {
        // Arrange

        $tagsManagerMock = $this->mock(TagsManager::class);

        $strataStore = resolve(StrataStore::class);

        // Anticipate

        $tagsManagerMock->allows('getIdOrCreate')->andReturn('id-1');

        // Act

        $strataTaggedCache = $strataStore->tags('assets', 'relations');
        $array = $strataStore->tags(['assets', 'relations']);

        // Assert

        $this->assertSame(['assets', 'relations'], $strataTaggedCache->getTags()->getNames());

        $this->assertSame(['assets', 'relations'], $array->getTags()->getNames());
    }

    public function test_it_returns_an_empty_prefix(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        // Act & Assert

        $this->assertSame('', $strataStore->getPrefix());
    }

    public function test_it_implements_the_lock_contracts_available_in_the_installed_laravel(): void
    {
        // Act

        $implements = class_implements(StrataStore::class);

        // Assert

        $this->assertContains(SafeLockProvider::class, $implements);

        $this->assertContains(LockProvider::class, $implements);

        $this->assertSame(
            interface_exists(CanFlushLocks::class),
            in_array(CanFlushLocks::class, $implements, true),
        );
    }

    public function test_it_adds_a_missing_value_as_a_serialized_payload(): void
    {
        // Arrange

        $this->freezeTime();

        $strataStore = resolve(StrataStore::class);

        // Act

        $added = $strataStore->add('key', 'value', 60);

        // Assert

        $this->assertTrue($added);

        $this->assertSame(
            ['value' => 'value', 'expires_at' => now()->getTimestamp() + 60],
            $this->cachedValuePayload('key'),
        );
    }

    public function test_it_does_not_add_over_a_live_value(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('key', 'first', 60);

        $contentsBefore = file_get_contents($this->cachedValueFilepath('key'));

        // Act

        $added = $strataStore->add('key', 'second', 120);

        // Assert

        $this->assertFalse($added);

        $this->assertSame($contentsBefore, file_get_contents($this->cachedValueFilepath('key')));
    }

    public function test_it_adds_over_an_expired_value(): void
    {
        // Arrange

        $this->freezeTime();

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('key', 'old', 60);

        $this->travel(60)->seconds();

        // Act

        $added = $strataStore->add('key', 'new', 60);

        // Assert

        $this->assertTrue($added);

        $this->assertSame('new', $strataStore->get('key'));
    }

    public function test_it_adds_over_a_corrupted_value(): void
    {
        // Arrange

        $this->createDummyCachedValue('key');

        $strataStore = resolve(StrataStore::class);

        // Act

        $added = $strataStore->add('key', 'value', 60);

        // Assert

        $this->assertTrue($added);

        $this->assertSame('value', $strataStore->get('key'));
    }

    public function test_it_adds_a_tagged_value_with_its_tags_in_the_payload(): void
    {
        // Arrange

        $this->freezeTime();

        $strataStore = resolve(StrataStore::class);

        // Act

        $strataStore->add('key', new TaggableValue('value', ['assets' => 'id-1']), 60);

        // Assert

        $this->assertSame(
            [
                'value' => 'value',
                'expires_at' => now()->getTimestamp() + 60,
                'tags' => ['assets' => 'id-1'],
            ],
            $this->cachedValuePayload('key'),
        );
    }

    public function test_it_adds_over_a_value_whose_tag_id_has_changed(): void
    {
        // Arrange

        $tagsManagerMock = $this->mock(TagsManager::class);

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('key', new TaggableValue('old', ['assets' => 'id-1']), 60);

        // Anticipate

        $tagsManagerMock->expects('getId')->with('assets')->andReturn('id-2');

        // Act

        $added = $strataStore->add('key', 'new', 60);

        // Assert

        $this->assertTrue($added);

        $this->assertSame(['value' => 'new', 'expires_at' => now()->getTimestamp() + 60], $this->cachedValuePayload('key'));
    }

    public function test_it_does_not_add_when_another_process_holds_the_file_lock(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('key', 'first', 60);

        $handle = fopen($this->cachedValueFilepath('key'), 'c+');

        flock($handle, LOCK_EX);

        // Act

        $added = $strataStore->add('key', 'second', 60);

        // Assert

        $this->assertFalse($added);

        flock($handle, LOCK_UN);

        fclose($handle);

        $this->assertSame('first', $strataStore->get('key'));
    }

    public function test_it_writes_added_files_with_the_configured_file_permission(): void
    {
        // Arrange

        config(['strata.file_permission' => $permission = 0o750]);

        $strataStore = resolve(StrataStore::class);

        // Act

        $strataStore->add('key', 'value', 60);

        // Assert

        $file = $this->cachedValuesFilePaths()[0];

        $this->assertSame($permission, $this->permissionsOf($file));

        $this->assertSame($permission, $this->permissionsOf(dirname($file)));

        $this->assertSame($permission, $this->permissionsOf(dirname($file, 2)));
    }

    public function test_it_keeps_locks_in_the_lock_directory_apart_from_cached_values(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        // Act

        $acquired = $strataStore->lock('orders', 60, 'owner-1')->get();

        // Assert

        $this->assertTrue($acquired);

        $this->assertFileExists($this->lockFilepath('orders'));

        $this->assertSame([], $this->cachedValuesFilePaths());
    }

    public function test_it_restores_a_lock_with_the_given_owner(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        $strataStore->lock('orders', 60, 'owner-1')->get();

        // Act

        $strataLock = $strataStore->restoreLock('orders', 'owner-1');

        // Assert

        $this->assertSame('owner-1', $strataLock->owner());

        $this->assertTrue($strataLock->release());
    }

    public function test_it_always_reports_a_separate_lock_directory(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        // Act & Assert

        $this->assertTrue($strataStore->hasSeparateLockStore());
    }

    public function test_it_does_not_accept_the_same_directory_for_locks_and_cached_values(): void
    {
        // Anticipate

        $this->expectException(InvalidArgumentException::class);

        $this->expectExceptionMessage('The lock directory must differ from the cache directory.');

        // Act & Assert

        new StrataStore(
            filesystem: resolve(Filesystem::class),
            cacheDir: $this->cacheDirectory.'/data',
            filePermission: 0o755,
            tagsManager: resolve(TagsManager::class),
            lockDirectory: $this->cacheDirectory.'/data',
        );
    }

    public function test_it_flushes_locks_without_touching_cached_values(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('key', 'value', 60);

        $strataStore->lock('orders', 60)->get();

        // Act

        $flushed = $strataStore->flushLocks();

        // Assert

        $this->assertTrue($flushed);

        $this->assertFileDoesNotExist($this->lockFilepath('orders'));

        $this->assertSame('value', $strataStore->get('key'));
    }

    public function test_it_does_not_flush_locks_when_there_is_no_lock_directory_on_disk(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        // Act & Assert

        $this->assertFalse($strataStore->flushLocks());
    }

    public function test_it_flushes_the_cache_without_releasing_locks(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        $strataStore->lock('orders', 60, 'owner-1')->get();

        // Act

        $strataStore->flush();

        // Assert

        $this->assertFileExists($this->lockFilepath('orders'));
    }

    public function test_it_reads_an_object_of_any_class_when_no_classes_are_restricted(): void
    {
        // Arrange

        $strataStore = $this->storeWithSerializableClasses(null);

        $strataStore->put('key', new stdClass, 60);

        // Act

        $value = $strataStore->get('key');

        // Assert

        $this->assertInstanceOf(stdClass::class, $value);
    }

    #[DataProvider('restrictedClassesProvider')]
    public function test_it_reads_an_object_of_a_class_that_is_not_allowed_as_an_incomplete_class(array|bool $serializableClasses): void
    {
        // Arrange

        $strataStore = $this->storeWithSerializableClasses($serializableClasses);

        $strataStore->put('key', new stdClass, 60);

        // Act

        $value = $strataStore->get('key');

        // Assert

        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $value);
    }

    /**
     * @return Generator<string, array{serializableClasses: array<int, class-string>|bool}>
     */
    public static function restrictedClassesProvider(): Generator
    {
        yield 'none allowed' => [
            'serializableClasses' => false,
        ];

        yield 'another class allowed' => [
            'serializableClasses' => [Filesystem::class],
        ];
    }

    #[DataProvider('allowedClassesProvider')]
    public function test_it_reads_an_object_of_an_allowed_class(array|bool $serializableClasses): void
    {
        // Arrange

        $strataStore = $this->storeWithSerializableClasses($serializableClasses);

        $strataStore->put('key', new stdClass, 60);

        // Act

        $value = $strataStore->get('key');

        // Assert

        $this->assertInstanceOf(stdClass::class, $value);
    }

    /**
     * @return Generator<string, array{serializableClasses: array<int, class-string>|bool}>
     */
    public static function allowedClassesProvider(): Generator
    {
        yield 'all allowed' => [
            'serializableClasses' => true,
        ];

        yield 'listed class' => [
            'serializableClasses' => [stdClass::class],
        ];
    }

    public function test_it_reads_a_value_while_another_handle_holds_a_shared_lock(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('key', 'value', 60);

        $handle = fopen($this->cachedValueFilepath('key'), 'rb');

        flock($handle, LOCK_SH);

        // Act

        $value = $strataStore->get('key');

        // Assert

        $this->assertSame('value', $value);

        fclose($handle);
    }

    public function test_it_waits_for_a_writer_instead_of_dropping_the_half_written_value(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        $strataStore->put('key', 'old', 60);

        // A separate process now holds the file locked and empty, the same state it is in mid-write.
        // It keeps that lock until it has written the new value, see startWriterHoldingTheLock() below.
        [$writer, $pipes] = $this->startWriterHoldingTheLock(
            path: $this->cachedValueFilepath('key'),
            contents: (now()->getTimestamp() + 60)."\n\n".serialize('new'),
        );

        // Act

        try {
            // The get() takes a shared lock before reading, so this call blocks
            // until the other process releases its lock.
            $value = $strataStore->get('key');
        } finally {
            fclose($pipes[1]);

            proc_close($writer);
        }

        // Assert

        $this->assertSame('new', $value);
    }

    public function test_it_still_drops_a_broken_value_when_it_reads_it(): void
    {
        // Arrange

        $filepath = $this->createDummyCachedValue('key');

        $strataStore = resolve(StrataStore::class);

        // Act & Assert

        $this->assertNull($strataStore->get('key'));

        $this->assertFileDoesNotExist($filepath);
    }

    public function test_it_throws_when_a_tag_name_is_not_valid_utf8_and_writes_nothing(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        // Anticipate

        $this->expectException(InvalidTagNameException::class);

        // Act & Assert

        try {
            $strataStore->put('key', new TaggableValue('value', ["\xB1\x31" => 'id-1']), 60);
        } finally {
            $this->assertSame([], $this->cachedValuesFilePaths());
        }
    }

    public function test_it_throws_on_add_when_a_tag_name_is_not_valid_utf8_and_leaves_no_file(): void
    {
        // Arrange

        $strataStore = resolve(StrataStore::class);

        // Anticipate

        $this->expectException(InvalidTagNameException::class);

        // Act & Assert

        try {
            $strataStore->add('key', new TaggableValue('value', ["\xB1\x31" => 'id-1']), 60);
        } finally {
            $this->assertSame([], $this->cachedValuesFilePaths());
        }
    }

    /*
     * Helpers.
     */

    /**
     * Spawn a subprocess that locks the given file, empties it, then writes its new contents after a short delay.
     * Blocks until the subprocess reports it holds the lock, so the caller can read the file while it is still empty.
     *
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function startWriterHoldingTheLock(string $path, string $contents): array
    {
        $script = <<<'PHP'
            // 1. Take the exclusive lock and empty the file, the state it is in mid-write.
            $handle = fopen($argv[1], 'c+');
            flock($handle, LOCK_EX);
            ftruncate($handle, 0);

            // 2. Tell the parent process it can now attempt its read, the file is locked and empty.
            echo "locked\n";
            fflush(STDOUT);

            // 3. Give the parent time to reach its own blocking read call below, while still holding the lock.
            usleep(30000);

            // 4. Write the real value and release the lock, which unblocks the parent's read.
            fwrite($handle, $argv[2]);
            fclose($handle);
        PHP;

        $writer = proc_open(
            command: [PHP_BINARY, '-r', $script, $path, $contents],
            descriptor_spec: [1 => ['pipe', 'w']],
            pipes: $pipes,
        );

        // Block here until step 2 above runs, so the caller only proceeds once the file is locked and empty.
        fgets($pipes[1]);

        return [$writer, $pipes];
    }

    /**
     * Spawn the given number of copies of the same command, then release them all at once.
     * Each subprocess must write a line to stdout once ready, then block on a read from stdin,
     * so this can hold every copy at that point until they all start their real work together.
     *
     * @param  array<int, string>  $command
     * @return array<int, resource>
     */
    private function startWorkersInLockStep(array $command, int $workers): array
    {
        $processes = [];

        $pipesByWorker = [];

        foreach (range(1, $workers) as $_) {
            $processes[] = proc_open(
                command: $command,
                descriptor_spec: [0 => ['pipe', 'r'], 1 => ['pipe', 'w']],
                pipes: $pipes,
            );

            $pipesByWorker[] = $pipes;
        }

        // Wait for every worker to report ready before releasing any of them.
        foreach ($pipesByWorker as $pipes) {
            fgets($pipes[1]);
        }

        foreach ($pipesByWorker as $pipes) {
            fwrite($pipes[0], "go\n");
            fclose($pipes[0]);
            fclose($pipes[1]);
        }

        return $processes;
    }

    /**
     * Build the command for a worker that reports ready, waits for the go signal from startWorkersInLockStep() above,
     * then increments the "counter" key the given number of times.
     *
     * @return array<int, string>
     */
    private function incrementCounterCommand(int $increments): array
    {
        $script = <<<'PHP'
            $filesystem = new Illuminate\Filesystem\Filesystem;

            $store = new Sunchayn\Strata\Modules\Cache\StrataStore(
                filesystem: $filesystem,
                cacheDir: $argv[1].'/data',
                filePermission: 0755,
                tagsManager: new Sunchayn\Strata\Modules\Cache\Services\TagsManager($filesystem, $argv[1].'/meta/tags', 0755),
                lockDirectory: $argv[1].'/locks',
            );

            echo "ready\n";
            fflush(STDOUT);

            fgets(STDIN);

            for ($i = 0; $i < (int) $argv[2]; $i++) {
                $store->increment('counter');
            }
        PHP;

        return [
            PHP_BINARY,
            '-r',
            'require '.var_export(__DIR__.'/../../../../vendor/autoload.php', true).';'.$script,
            $this->cacheDirectory,
            (string) $increments,
        ];
    }

    /**
     * @param  array<int, class-string>|bool|null  $serializableClasses
     */
    private function storeWithSerializableClasses(array|bool|null $serializableClasses): StrataStore
    {
        return new StrataStore(
            filesystem: resolve(Filesystem::class),
            cacheDir: $this->cacheDirectory.'/data',
            filePermission: 0o755,
            tagsManager: resolve(TagsManager::class),
            lockDirectory: $this->cacheDirectory.'/locks',
            serializableClasses: $serializableClasses,
        );
    }
}

final class UnserializeValue
{
    public static bool $unserialized = false;

    public function __wakeup(): void
    {
        self::$unserialized = true;
    }
}
