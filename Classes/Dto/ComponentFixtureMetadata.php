<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Dto;

final readonly class ComponentFixtureMetadata
{
    /**
     * @param list<ComponentVariantMetadata> $variants
     */
    public function __construct(
        public ?string $absolutePath,
        public ?string $extensionPath,
        public ?string $content,
        public array $variants,
        public ?string $error,
        public ?ComponentVariantMetadata $selectedVariant = null,
    ) {
    }
}
