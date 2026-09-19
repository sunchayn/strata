<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Modules\Cache\ValueObjects;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<int, mixed>
 */
readonly class TaggableValue implements Arrayable
{
    /**
     * @param  array<string, string>  $tags  Each tag name mapped to its id.
     */
    public function __construct(
        public mixed $value,
        public array $tags,
    ) {}

    public function unwrap(): mixed
    {
        return $this->value;
    }

    /**
     * @return array{0: mixed, 1: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            $this->value,
            $this->tags,
        ];
    }
}
