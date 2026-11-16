<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures;

final readonly class TeaserCard
{
    public function __construct(
        public string $headline,
        public TeaserLink $link,
        public ?TeaserLink $secondaryLink = null,
    ) {
    }
}
