<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures;

final readonly class TagList
{
    /** @var array<string> */
    public array $tags;

    public function __construct(string ...$tags)
    {
        $this->tags = $tags;
    }
}
