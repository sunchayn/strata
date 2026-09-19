<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Tests\App\Modules\Cache\ValueObjects;

use Generator;
use Illuminate\Contracts\Support\Arrayable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use Sunchayn\Strata\Modules\Cache\ValueObjects\TaggableValue;

#[CoversClass(TaggableValue::class)]
final class TaggableValueUnitTest extends TestCase
{
    /**
     * @param  array<string, string>  $tags
     */
    #[DataProvider('tagsProvider')]
    public function test_it_converts_to_a_value_and_tags_pair(array $tags): void
    {
        // Arrange

        $taggableValue = new TaggableValue('books', $tags);

        // Act & Assert

        $this->assertSame(['books', $tags], $taggableValue->toArray());
    }

    /**
     * @return Generator<string, array{tags: array<string, string>}>
     */
    public static function tagsProvider(): Generator
    {
        yield 'with tags' => [
            'tags' => ['assets' => 'id-1', 'relations' => 'id-2'],
        ];

        yield 'with an empty tag list' => [
            'tags' => [],
        ];
    }

    #[DataProvider('valueProvider')]
    public function test_it_keeps_any_value_type_untouched(mixed $value): void
    {
        // Arrange

        $taggableValue = new TaggableValue($value, ['assets' => 'id-1']);

        // Act & Assert

        $this->assertSame($value, $taggableValue->unwrap());

        $this->assertSame($value, $taggableValue->toArray()[0]);
    }

    /**
     * @return Generator<string, array{value: mixed}>
     */
    public static function valueProvider(): Generator
    {
        yield 'null' => [
            'value' => null,
        ];

        yield 'false' => [
            'value' => false,
        ];

        yield 'zero' => [
            'value' => 0,
        ];

        yield 'empty string' => [
            'value' => '',
        ];

        yield 'empty array' => [
            'value' => [],
        ];

        yield 'array' => [
            'value' => ['id' => 1],
        ];

        yield 'float' => [
            'value' => 1.5,
        ];

        yield 'object' => [
            'value' => new stdClass,
        ];
    }

    public function test_it_is_arrayable(): void
    {
        $this->assertContains(
            Arrayable::class,
            class_implements(TaggableValue::class),
        );
    }

    public function test_it_is_a_readonly_class(): void
    {
        // Arrange

        $reflection = new ReflectionClass(TaggableValue::class);

        // Act & Assert

        $this->assertTrue($reflection->isReadOnly());
    }

    public function test_it_survives_serialization(): void
    {
        // Arrange

        $taggableValue = new TaggableValue(['id' => 1], ['assets' => 'id-1']);

        // Act

        $restored = unserialize(serialize($taggableValue));

        // Assert

        $this->assertEquals($taggableValue, $restored);
    }
}
