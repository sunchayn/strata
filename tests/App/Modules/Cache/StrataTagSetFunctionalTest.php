<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Tests\App\Modules\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use Sunchayn\Strata\Modules\Cache\Services\TagsManager;
use Sunchayn\Strata\Modules\Cache\StrataStore;
use Sunchayn\Strata\Modules\Cache\StrataTagSet;
use Sunchayn\Strata\Tests\TestCase;

#[CoversClass(StrataTagSet::class)]
final class StrataTagSetFunctionalTest extends TestCase
{
    public function test_it_lists_the_names_of_its_tags(): void
    {
        // Arrange

        $tagSet = $this->tagSet($this->mock(TagsManager::class), ['assets', 'relations']);

        // Act & Assert

        $this->assertSame(['assets', 'relations'], $tagSet->getNames());
    }

    public function test_it_asks_the_tags_manager_for_the_id_of_a_tag(): void
    {
        // Arrange

        $tagsManagerMock = $this->mock(TagsManager::class);

        $tagSet = $this->tagSet($tagsManagerMock, ['assets']);

        // Anticipate

        $tagsManagerMock->expects('getIdOrCreate')->with('relations')->andReturn('id-1');

        // Act

        $id = $tagSet->tagId('relations');

        // Assert

        $this->assertSame('id-1', $id);
    }

    public function test_it_asks_the_tags_manager_to_rotate_the_id_of_a_tag_when_resetting_it(): void
    {
        // Arrange

        $tagsManagerMock = $this->mock(TagsManager::class);

        $tagSet = $this->tagSet($tagsManagerMock, ['assets', 'relations']);

        // Anticipate

        $tagsManagerMock->expects('rotateId')->with('assets')->andReturn('id-2');

        // Act

        $newId = $tagSet->resetTag('assets');

        // Assert

        $this->assertSame('id-2', $newId);
    }

    public function test_it_asks_the_tags_manager_to_rotate_the_id_of_every_tag_it_holds_when_reset(): void
    {
        // Arrange

        $tagsManagerMock = $this->mock(TagsManager::class);

        $tagSet = $this->tagSet($tagsManagerMock, ['assets', 'relations']);

        // Anticipate

        $tagsManagerMock->expects('rotateId')->with('assets')->andReturn('id-1');

        $tagsManagerMock->expects('rotateId')->with('relations')->andReturn('id-2');

        // Act & Assert

        $tagSet->reset();
    }

    public function test_it_asks_the_tags_manager_to_delete_a_tag_when_flushing_it(): void
    {
        // Arrange

        $tagsManagerMock = $this->mock(TagsManager::class);

        $tagSet = $this->tagSet($tagsManagerMock, ['assets', 'relations']);

        // Anticipate

        $tagsManagerMock->expects('delete')->with('assets');

        // Act & Assert

        $tagSet->flushTag('assets');
    }

    public function test_it_does_not_decorate_the_tag_key(): void
    {
        // Arrange

        $tagSet = $this->tagSet($this->mock(TagsManager::class), ['assets']);

        // Act & Assert

        $this->assertSame('assets', $tagSet->tagKey('assets'));
    }

    public function test_it_has_no_namespace(): void
    {
        // Arrange

        $tagSet = $this->tagSet($this->mock(TagsManager::class), ['assets', 'relations']);

        // Act & Assert

        $this->assertSame('', $tagSet->getNamespace());
    }

    /*
     * Helpers.
     */

    /**
     * @param  array<int, string>  $names
     */
    private function tagSet(TagsManager $tagsManager, array $names): StrataTagSet
    {
        return resolve(StrataTagSet::class, [
            'store' => $this->mock(StrataStore::class),
            'tagsManager' => $tagsManager,
            'names' => $names,
        ]);
    }
}
