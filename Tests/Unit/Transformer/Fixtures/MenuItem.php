<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures;

final readonly class MenuItem
{
    public function __construct(
        public string $label,
        public ?MenuItem $parent = null,
    ) {
    }
}
