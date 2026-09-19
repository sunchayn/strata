<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Tests\Integration\Modules\Cache;

use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use Sunchayn\Strata\Modules\Cache\StrataTaggedCache;
use Sunchayn\Strata\Tests\TestCase;

#[CoversClass(StrataTaggedCache::class)]
final class StrataTaggedCacheIntegrationTest extends TestCase
{
    public function test_it_integrates(): void
    {
        // Arrange

        $cache = Cache::store('strata')->tags(['assets']);

        $cache->put('books:1', ['id' => 1], 60);

        $beforeFlush = $cache->get('books:1');

        // Act

        $cache->flush();

        // Assert

        $this->assertSame(['id' => 1], $beforeFlush);

        $this->assertNull(Cache::store('strata')->get('books:1'));
    }

    public function test_it_reads_a_value_written_after_a_flush_on_the_same_instance(): void
    {
        // Arrange

        $cache = Cache::store('strata')->tags(['assets']);

        $cache->put('books:1', ['id' => 1], 60);

        // Act

        $cache->flush();

        $cache->put('books:2', ['id' => 2], 60);

        // Assert

        $this->assertNull($cache->get('books:1'));

        $this->assertSame(['id' => 2], $cache->get('books:2'));
    }
}
