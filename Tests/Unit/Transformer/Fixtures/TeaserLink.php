<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures;

use Andersundsehr\FrontendStudio\Control\Attribute\Control;

final readonly class TeaserLink
{
    public function __construct(
        public string $url,
        public LinkTarget $target = LinkTarget::Self,
        #[Control('frontend-studio.rich-text')]
        public ?string $title = null,
        public int|null $position = null,
    ) {
    }
}
