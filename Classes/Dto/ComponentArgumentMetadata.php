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
}
