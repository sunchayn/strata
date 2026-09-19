<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Tests\Integration\Modules\Cache;

use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversNothing;
use Sunchayn\Strata\Modules\Cache\StrataStore;
use Sunchayn\Strata\Tests\TestCase;

#[CoversNothing]
final class CacheFacadeIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_computes_a_remembered_value_only_once(): void
    {
        // Arrange

        $calls = 0;

        $callback = function () use (&$calls): string {
            $calls++;

            return 'computed';
        };

        // Act

        $first = Cache::store('strata')->remember('report', 60, $callback);

        $second = Cache::store('strata')->remember('report', 60, $callback);

        // Assert

        $this->assertSame('computed', $first);

        $this->assertSame('computed', $second);

        $this->assertSame(1, $calls);
    }

    public function test_it_keeps_a_flexible_value_within_its_fresh_window(): void
    {
        // Arrange

        $calls = 0;

        $callback = function () use (&$calls): string {
            $calls++;

            return 'computed';
        };

        // Act

        $first = Cache::store('strata')->flexible('report', [60, 120], $callback);

        $second = Cache::store('strata')->flexible('report', [60, 120], $callback);

        // Assert

        $this->assertSame('computed', $first);

        $this->assertSame('computed', $second);

        $this->assertSame(1, $calls);
    }

    public function test_it_refreshes_a_stale_flexible_value_synchronously_without_deferring(): void
    {
        // Arrange

        $calls = 0;

        $callback = function () use (&$calls): string {
            $calls++;

            return "computed-{$calls}";
        };

        Cache::store('strata')->flexible('report', [60, 120], $callback);

        $this->withoutDefer();

        Carbon::setTestNow(now()->addSeconds(61));

        // Act

        $stale = Cache::store('strata')->flexible('report', [60, 120], $callback);

        $refreshed = Cache::store('strata')->get('report');

        // Assert

        $this->assertSame('computed-1', $stale);

        $this->assertSame(2, $calls);

        $this->assertSame('computed-2', $refreshed);
    }

    public function test_it_dispatches_the_plain_value_on_a_tagged_write(): void
    {
        // Arrange

        Event::fake([WritingKey::class, KeyWritten::class]);

        // Act

        Cache::store('strata')->tags(['assets'])->put('books:1', 'value', 60);

        // Assert

        Event::assertDispatched(
            KeyWritten::class,
            function (KeyWritten $event): bool {
                $this->assertSame('value', $event->value);

                return true;
            },
        );
    }

    public function test_it_flushes_only_the_tagged_key(): void
    {
        // Arrange

        Cache::store('strata')->tags(['assets'])->put('a', 'v', 60);

        Cache::store('strata')->put('b', 'v', 60);

        // Act

        Cache::store('strata')->tags(['assets'])->flush();

        // Assert

        $this->assertNull(Cache::store('strata')->get('a'));

        $this->assertSame('v', Cache::store('strata')->get('b'));
    }

    public function test_it_prevents_a_second_lock_from_being_acquired_while_the_first_is_held(): void
    {
        // Arrange

        $first = Cache::store('strata')->lock('import', 10);

        $second = Cache::store('strata')->lock('import', 10);

        // Act

        $firstAcquired = $first->get();

        $secondAcquired = $second->get();

        $first->release();

        $acquiredAfterRelease = $second->get();

        // Assert

        $this->assertTrue($firstAcquired);

        $this->assertFalse($secondAcquired);

        $this->assertTrue($acquiredAfterRelease);
    }

    public function test_it_writes_and_reads_a_value_through_set_and_get(): void
    {
        // Act

        Cache::store('strata')->set('books:1', 'value');

        // Assert

        $this->assertSame('value', Cache::store('strata')->get('books:1'));
    }

    public function test_it_writes_and_reads_a_value_through_put_and_get(): void
    {
        // Act

        Cache::store('strata')->put('books:1', 'value', 60);

        // Assert

        $this->assertSame('value', Cache::store('strata')->get('books:1'));
    }

    public function test_it_deletes_a_value_put_with_a_zero_ttl(): void
    {
        // Arrange

        Cache::store('strata')->put('books:1', 'value', 60);

        // Act

        Cache::store('strata')->put('books:1', 'other', 0);

        // Assert

        $this->assertNull(Cache::store('strata')->get('books:1'));
    }

    public function test_it_reports_presence_and_absence_of_a_key(): void
    {
        // Arrange

        $hasBefore = Cache::store('strata')->has('books:1');

        $missingBefore = Cache::store('strata')->missing('books:1');

        // Act

        Cache::store('strata')->put('books:1', 'value', 60);

        // Assert

        $this->assertFalse($hasBefore);

        $this->assertTrue($missingBefore);

        $this->assertTrue(Cache::store('strata')->has('books:1'));

        $this->assertFalse(Cache::store('strata')->missing('books:1'));
    }

    public function test_it_pulls_a_value_and_removes_it(): void
    {
        // Arrange

        Cache::store('strata')->put('books:1', 'value', 60);

        // Act

        $pulled = Cache::store('strata')->pull('books:1');

        // Assert

        $this->assertSame('value', $pulled);

        $this->assertNull(Cache::store('strata')->get('books:1'));
    }

    public function test_it_reads_many_keys_with_a_missing_one_as_null(): void
    {
        // Arrange

        Cache::store('strata')->put('books:1', 'value-1', 60);

        Cache::store('strata')->put('books:2', 'value-2', 60);

        // Act

        $many = Cache::store('strata')->many(['books:1', 'books:2', 'books:3']);

        $getMultiple = iterator_to_array(Cache::store('strata')->getMultiple(['books:1', 'books:2', 'books:3']));

        // Assert

        $expected = [
            'books:1' => 'value-1',
            'books:2' => 'value-2',
            'books:3' => null,
        ];

        $this->assertSame($expected, $many);

        $this->assertSame($expected, $getMultiple);
    }

    public function test_it_writes_many_keys_and_reads_each_back(): void
    {
        // Act

        Cache::store('strata')->putMany([
            'books:1' => 'value-1',
            'books:2' => 'value-2',
        ], 60);

        Cache::store('strata')->setMultiple([
            'books:3' => 'value-3',
            'books:4' => 'value-4',
        ]);

        // Assert

        $this->assertSame('value-1', Cache::store('strata')->get('books:1'));

        $this->assertSame('value-2', Cache::store('strata')->get('books:2'));

        $this->assertSame('value-3', Cache::store('strata')->get('books:3'));

        $this->assertSame('value-4', Cache::store('strata')->get('books:4'));
    }

    public function test_it_adds_a_value_only_when_the_key_is_fresh(): void
    {
        // Act

        $first = Cache::store('strata')->add('books:1', 'value', 60);

        $second = Cache::store('strata')->add('books:1', 'other', 60);

        // Assert

        $this->assertTrue($first);

        $this->assertFalse($second);

        $this->assertSame('value', Cache::store('strata')->get('books:1'));
    }

    public function test_it_increments_and_decrements_a_numeric_value(): void
    {
        // Arrange

        Cache::store('strata')->put('visits', 10, 60);

        // Act

        $incremented = Cache::store('strata')->increment('visits', 5);

        $decremented = Cache::store('strata')->decrement('visits', 3);

        // Assert

        $this->assertSame(15, $incremented);

        $this->assertSame(12, $decremented);
    }

    public function test_it_stores_a_value_forever(): void
    {
        // Act

        Cache::store('strata')->forever('books:1', 'value');

        // Assert

        $this->assertSame('value', Cache::store('strata')->get('books:1'));
    }

    public function test_it_computes_a_seared_value_only_once(): void
    {
        // Arrange

        $calls = 0;

        $callback = function () use (&$calls): string {
            $calls++;

            return 'computed';
        };

        // Act

        $first = Cache::store('strata')->sear('report', $callback);

        $second = Cache::store('strata')->sear('report', $callback);

        // Assert

        $this->assertSame('computed', $first);

        $this->assertSame('computed', $second);

        $this->assertSame(1, $calls);
    }

    public function test_it_extends_a_key_ttl_when_touched(): void
    {
        // Arrange

        Cache::store('strata')->put('books:1', 'value', 60);

        // Act

        Cache::store('strata')->touch('books:1', 120);

        Carbon::setTestNow(now()->addSeconds(90));

        // Assert

        $this->assertSame('value', Cache::store('strata')->get('books:1'));
    }

    public function test_it_forgets_a_single_key(): void
    {
        // Arrange

        Cache::store('strata')->put('books:1', 'value', 60);

        // Act

        Cache::store('strata')->forget('books:1');

        // Assert

        $this->assertNull(Cache::store('strata')->get('books:1'));
    }

    public function test_it_deletes_a_single_key(): void
    {
        // Arrange

        Cache::store('strata')->put('books:1', 'value', 60);

        // Act

        Cache::store('strata')->delete('books:1');

        // Assert

        $this->assertNull(Cache::store('strata')->get('books:1'));
    }

    public function test_it_deletes_multiple_keys(): void
    {
        // Arrange

        Cache::store('strata')->put('books:1', 'value-1', 60);

        Cache::store('strata')->put('books:2', 'value-2', 60);

        // Act

        Cache::store('strata')->deleteMultiple(['books:1', 'books:2']);

        // Assert

        $this->assertNull(Cache::store('strata')->get('books:1'));

        $this->assertNull(Cache::store('strata')->get('books:2'));
    }

    public function test_it_clears_every_key(): void
    {
        // Arrange

        Cache::store('strata')->put('books:1', 'value', 60);

        Cache::store('strata')->put('books:2', 'value', 60);

        // Act

        Cache::store('strata')->clear();

        // Assert

        $this->assertNull(Cache::store('strata')->get('books:1'));

        $this->assertNull(Cache::store('strata')->get('books:2'));
    }

    public function test_it_exposes_the_underlying_strata_store(): void
    {
        // Act & Assert

        $this->assertInstanceOf(StrataStore::class, Cache::store('strata')->getStore());
    }
}
