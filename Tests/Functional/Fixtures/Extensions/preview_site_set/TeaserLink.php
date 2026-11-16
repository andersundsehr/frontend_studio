<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Functional\Fixtures\Extensions\preview_site_set;

final readonly class TeaserLink
{
    public function __construct(
        public string $url,
        public TeaserLinkTarget $target = TeaserLinkTarget::Self,
        public ?string $title = null,
    ) {
    }
}
