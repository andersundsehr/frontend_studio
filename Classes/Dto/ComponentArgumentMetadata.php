<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Dto;

final readonly class ComponentArgumentMetadata
{
    /**
     * @param list<class-string> $annotations
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $description,
        public bool $required,
        public string $defaultValue,
        public string $escape,
        public array $annotations,
    ) {
    }

    public function getShortType(): string
    {
        return preg_replace('/(?:\\\\)?(?:[A-Za-z_][A-Za-z0-9_]*\\\\)+([A-Za-z_][A-Za-z0-9_]*)/', '$1', $this->type) ?? $this->type;
    }
}
