<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Control\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Control
{
    public function __construct(public string $id)
    {
    }
}
