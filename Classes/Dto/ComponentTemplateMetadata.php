<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Dto;

final readonly class ComponentTemplateMetadata
{
    /**
     * @param list<ComponentTemplateRootPathMetadata> $rootPaths
     */
    public function __construct(
        public string $name,
        public ?string $absolutePath,
        public ?string $extensionPath,
        public ?string $relativePath,
        public array $rootPaths,
        public ?string $content,
        public ?string $error,
    ) {
    }
}
