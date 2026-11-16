<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures;

use Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures\TeaserLink as Link;
use DateTimeImmutable;

final readonly class TeaserList
{
    /**
     * @param list<Link> $links
     * @param array<string, DateTimeImmutable> $dates
     * @param array<mixed> $tags
     */
    public function __construct(
        public string $headline,
        public array $links = [],
        /** @var list<LinkTarget> */
        public array $targets = [],
        public array $dates = [],
        public array $tags = [],
    ) {
    }
}
