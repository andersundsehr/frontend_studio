<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Dto;

final readonly class ComponentTemplateRootPathMetadata
{
    public function __construct(
        public string|int $priority,
        public string $absolutePath,
        public ?string $extensionPath,
    ) {
    }
}
