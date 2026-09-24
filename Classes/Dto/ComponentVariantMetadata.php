<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Dto;

final readonly class ComponentVariantMetadata
{
    /**
     * @param list<ComponentVariantValueMetadata> $values
     * @param array<string, string> $slots
     */
    public function __construct(
        public string $name,
        public array $values,
        public array $slots = [],
    ) {
    }
}
