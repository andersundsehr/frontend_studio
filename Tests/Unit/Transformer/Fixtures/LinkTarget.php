<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures;

enum LinkTarget: string
{
    case Self = '_self';
    case Blank = '_blank';
}
