<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Tests\Integration\Console\Commands;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\CoversClass;
use Sunchayn\Strata\Console\Commands\PruneStaleCacheTagsCommand;
use Sunchayn\Strata\Modules\Cache\Services\TagsManager;
use Sunchayn\Strata\Tests\TestCase;

#[CoversClass(PruneStaleCacheTagsCommand::class)]
final class PruneStaleCacheTagsCommandIntegrationTest extends TestCase
{
    public function test_it_is_registered_with_artisan(): void
    {
        // Act & Assert

        $this->assertArrayHasKey('strata:prune-stale-tags', Artisan::all());
    }

    public function test_it_prunes_with_the_configured_ttl(): void
    {
        // Arrange

        config(['strata.tag_gc_ttl' => 120]);

        $tagsManagerSpy = $this->spy(TagsManager::class);

        // Anticipate

        $tagsManagerSpy->allows('prune')->andReturn(0);

        // Act

        $this->artisan(PruneStaleCacheTagsCommand::class)->assertSuccessful();

        // Assert

        $tagsManagerSpy
            ->shouldHaveReceived('prune')
            ->once()
            ->withArgs(function (int $ttlSeconds): bool {
                $this->assertSame(120, $ttlSeconds);

                return true;
            });
    }

    public function test_it_reports_the_number_of_pruned_tags(): void
    {
        // Arrange

        $tagsManagerMock = $this->mock(TagsManager::class);

        // Anticipate

        $tagsManagerMock->allows('prune')->andReturn(3);

        // Act & Assert

        $this->artisan(PruneStaleCacheTagsCommand::class)
            ->expectsOutputToContain('Pruned 3 stale cache tag file(s).')
            ->assertSuccessful();
    }

    public function test_it_casts_the_configured_ttl_to_an_integer(): void
    {
        // Arrange

        config(['strata.tag_gc_ttl' => '120']);

        $tagsManagerSpy = $this->spy(TagsManager::class);

        // Anticipate

        $tagsManagerSpy->allows('prune')->andReturn(0);

        // Act

        $this->artisan(PruneStaleCacheTagsCommand::class)->assertSuccessful();

        // Assert

        $tagsManagerSpy
            ->shouldHaveReceived('prune')
            ->once()
            ->withArgs(function (int $ttlSeconds): bool {
                $this->assertSame(120, $ttlSeconds);

                return true;
            });
    }

    public function test_it_reports_zero_when_nothing_was_pruned(): void
    {
        // Arrange

        $tagsManagerMock = $this->mock(TagsManager::class);

        // Anticipate

        $tagsManagerMock->allows('prune')->andReturn(0);

        // Act & Assert

        $this->artisan(PruneStaleCacheTagsCommand::class)
            ->expectsOutputToContain('Pruned 0 stale cache tag file(s).')
            ->assertSuccessful();
    }

    public function test_it_integrates(): void
    {
        // Arrange

        config(['strata.tag_gc_ttl' => 60]);

        $this->freezeTime();

        resolve(TagsManager::class)->rotateId('assets');

        $this->travel(61)->seconds();

        // Act & Assert

        $this->artisan('strata:prune-stale-tags')->assertSuccessful();
    }
}
