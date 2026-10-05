<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Dto;

final readonly class MissingTransformersMetadata
{
    /** @param array<string, string> $arguments */
    public function __construct(
        public string $absolutePath,
        public string $relativePath,
        public array $arguments,
        public bool $canGenerate,
    ) {
    }
}
