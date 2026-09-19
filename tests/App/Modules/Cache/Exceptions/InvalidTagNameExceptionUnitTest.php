<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Tests\App\Modules\Cache\Exceptions;

use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sunchayn\Strata\Modules\Cache\Exceptions\InvalidTagNameException;

#[CoversClass(InvalidTagNameException::class)]
final class InvalidTagNameExceptionUnitTest extends TestCase
{
    public function test_it_builds_not_encodable(): void
    {
        // Arrange

        $jsonException = new JsonException('Malformed UTF-8 characters');

        $invalidTagNameException = InvalidTagNameException::becauseNotEncodable($jsonException);

        // Assert

        $this->assertSame(
            'A tag name is not valid UTF-8. It cannot be stored in a cache file.',
            $invalidTagNameException->getMessage(),
        );

        $this->assertSame($jsonException, $invalidTagNameException->getPrevious());
    }
}
