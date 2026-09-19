<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Modules\Cache\Exceptions;

use InvalidArgumentException;
use JsonException;

final class InvalidTagNameException extends InvalidArgumentException
{
    public static function becauseNotEncodable(JsonException $previous): self
    {
        return new self(
            'A tag name is not valid UTF-8. It cannot be stored in a cache file.',
            previous: $previous,
        );
    }
}
