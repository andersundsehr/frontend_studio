<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Dto;

use Andersundsehr\FrontendStudio\Control\ControlDefinition;

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
        public ?string $parentName = null,
        public ?string $fixtureName = null,
        public bool $isDate = false,
        /** @var array<string, string> */
        public array $options = [],
        public ?string $transformerSource = null,
        public ?ControlDefinition $control = null,
    ) {
    }

    public function getShortType(): string
    {
        return preg_replace('/(?:\\\\)?(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+([A-Za-z_][A-Za-z0-9_]*)/', '$1', $this->type) ?? $this->type;
    }
}
