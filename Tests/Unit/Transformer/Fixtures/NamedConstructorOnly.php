<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures;

final readonly class NamedConstructorOnly
{
    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }
}
