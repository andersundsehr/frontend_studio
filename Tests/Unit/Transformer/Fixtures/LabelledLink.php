<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures;

use Stringable;

final readonly class LabelledLink
{
    public function __construct(
        public string $url,
        public Stringable $label,
    ) {
    }
}
