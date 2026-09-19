<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Tests\Integration\Modules\Cache;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use Sunchayn\Strata\Modules\Cache\StrataStore;
use Sunchayn\Strata\Modules\Cache\StrataTaggedCache;
use Sunchayn\Strata\Tests\TestCase;

#[CoversClass(StrataStore::class)]
#[CoversClass(StrataTaggedCache::class)]
final class StrataStoreLockIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_adds_a_value_only_when_the_key_is_missing(): void
    {
        // Arrange

        $repository = Cache::store('strata');

        // Act

        $first = $repository->add('books:1', 'first', 60);

        $second = $repository->add('books:1', 'second', 60);

        // Assert

        $this->assertTrue($first);

        $this->assertFalse($second);

        $this->assertSame('first', $repository->get('books:1'));
    }

    public function test_it_adds_a_value_over_an_expired_one(): void
    {
        // Arrange

        $repository = Cache::store('strata');

        $repository->put('books:1', 'old', 60);

        Carbon::setTestNow(now()->addSeconds(61));

        // Act

        $added = $repository->add('books:1', 'new', 60);

        // Assert

        $this->assertTrue($added);

        $this->assertSame('new', $repository->get('books:1'));
    }

    public function test_it_adds_a_value_over_one_with_a_flushed_tag(): void
    {
        // Arrange

        $repository = Cache::store('strata');

        $repository->tags(['assets'])->put('books:1', 'old', 60);

        $repository->tags(['assets'])->flush();

        // Act

        $added = $repository->add('books:1', 'new', 60);

        // Assert

        $this->assertTrue($added);

        $this->assertSame('new', $repository->get('books:1'));
    }

    public function test_it_keeps_the_tags_of_an_added_value(): void
    {
        // Arrange

        $repository = Cache::store('strata');

        // Act

        $added = $repository->tags(['assets'])->add('books:1', 'value', 60);

        $beforeFlush = $repository->get('books:1');

        $repository->tags(['assets'])->flush();

        // Assert

        $this->assertTrue($added);

        $this->assertSame('value', $beforeFlush);

        $this->assertNull($repository->get('books:1'));
    }

    public function test_it_acquires_a_lock_once_until_it_is_released(): void
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

    public function test_it_does_not_release_a_lock_held_by_another_owner(): void
    {
        // Arrange

        Cache::store('strata')->lock('import', 10)->get();

        $other = Cache::store('strata')->lock('import', 10);

        // Act

        $released = $other->release();

        // Assert

        $this->assertFalse($released);

        $this->assertFalse(Cache::store('strata')->lock('import', 10)->get());
    }

    public function test_it_restores_a_lock_with_its_owner(): void
    {
        // Arrange

        $lock = Cache::store('strata')->lock('import', 10);

        $lock->get();

        $restored = Cache::store('strata')->restoreLock('import', $lock->owner());

        // Act

        $released = $restored->release();

        // Assert

        $this->assertTrue($released);

        $this->assertTrue(Cache::store('strata')->lock('import', 10)->get());
    }

    public function test_it_refreshes_a_lock_held_by_the_owner(): void
    {
        // Arrange

        $lock = Cache::store('strata')->lock('import', 10);

        $lock->get();

        Carbon::setTestNow(now()->addSeconds(8));

        // Act

        $refreshed = $lock->refresh(10);

        Carbon::setTestNow(now()->addSeconds(5));

        // Assert

        $this->assertTrue($refreshed);

        $this->assertFalse(Cache::store('strata')->lock('import', 10)->get());
    }

    public function test_it_does_not_refresh_an_expired_lock(): void
    {
        // Arrange

        $lock = Cache::store('strata')->lock('import', 10);

        $lock->get();

        Carbon::setTestNow(now()->addSeconds(11));

        // Act

        $refreshed = $lock->refresh(10);

        // Assert

        $this->assertFalse($refreshed);
    }

    public function test_it_does_not_refresh_a_lock_held_by_another_owner(): void
    {
        // Arrange

        Cache::store('strata')->lock('import', 10)->get();

        $other = Cache::store('strata')->lock('import', 10);

        // Act

        $refreshed = $other->refresh(10);

        // Assert

        $this->assertFalse($refreshed);
    }

    public function test_it_flushes_locks_without_touching_cached_values(): void
    {
        // Arrange

        $repository = Cache::store('strata');

        $repository->put('books:1', 'value', 60);

        $repository->lock('import', 10)->get();

        // Act

        $flushed = $repository->getStore()->flushLocks();

        // Assert

        $this->assertTrue($flushed);

        $this->assertTrue($repository->lock('import', 10)->get());

        $this->assertSame('value', $repository->get('books:1'));
    }

    public function test_it_keeps_locks_when_the_cache_is_flushed(): void
    {
        // Arrange

        $repository = Cache::store('strata');

        $repository->put('books:1', 'value', 60);

        $repository->lock('import', 10)->get();

        // Act

        $repository->flush();

        // Assert

        $this->assertNull($repository->get('books:1'));

        $this->assertFalse($repository->lock('import', 10)->get());
    }
}
