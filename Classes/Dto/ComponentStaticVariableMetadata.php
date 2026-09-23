<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Dto;

final readonly class ComponentStaticVariableMetadata
{
    public function __construct(
        public string $name,
        public string $type,
        public string $value,
    ) {
    }
}
