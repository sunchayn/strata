<?php

declare(strict_types=1);

namespace Sunchayn\Strata\Bench\Renderers;

use Sunchayn\Strata\Bench\Contracts\Renderer;

final class MarkdownRenderer implements Renderer
{
    public function heading(string $text): void
    {
        echo "## {$text}".PHP_EOL.PHP_EOL;
    }

    public function text(string $text): void
    {
        echo $text.PHP_EOL.PHP_EOL;
    }

    public function table(array $headers, array $rows): void
    {
        echo '| '.implode(' | ', $headers).' |'.PHP_EOL;
        echo '|'.str_repeat(' --- |', count($headers)).PHP_EOL;

        foreach ($rows as $row) {
            echo '| '.implode(' | ', $row).' |'.PHP_EOL;
        }

        echo PHP_EOL;
    }
}
