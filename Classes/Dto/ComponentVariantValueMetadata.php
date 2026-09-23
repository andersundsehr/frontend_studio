<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Dto;

final readonly class ComponentVariantValueMetadata
{
    public function __construct(
        public string $name,
        public string $type,
        public string $description,
        public string $value,
        public mixed $nativeValue,
        public bool $isMultiline,
        public bool $isFixtureValue,
        public bool $required,
    ) {
    }
}
