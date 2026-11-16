<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Functional\Fixtures\Extensions\preview_site_set;

final readonly class TeaserCard
{
    /**
     * @param list<TeaserLink> $moreLinks
     */
    public function __construct(
        public string $headline,
        public TeaserLink $link,
        public array $moreLinks = [],
    ) {
    }
}
