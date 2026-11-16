<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures;

final readonly class Identifier
{
    public function __construct(public string|int $value)
    {
    }
}
