<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Bench\Contracts;

/**
 * How the Runner prints a scenario's section heading.
 */
interface Renderer
{
    public function heading(string $text): void;

    public function text(string $text): void;

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     */
    public function table(array $headers, array $rows): void;
}
