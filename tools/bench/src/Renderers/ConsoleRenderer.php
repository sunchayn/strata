<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Bench\Renderers;

use Sunchayn\Strata\Bench\Contracts\Renderer;

use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\table;

final class ConsoleRenderer implements Renderer
{
    public function heading(string $text): void
    {
        info($text);
    }

    public function text(string $text): void
    {
        note($text);
    }

    public function table(array $headers, array $rows): void
    {
        table($headers, $rows);
    }
}
