<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use InvalidArgumentException;
use RuntimeException;

final readonly class ComponentTransformerGenerator
{
    public function __construct(
        private ComponentMetadataProvider $metadataProvider,
        private TransformerTemplateGenerator $templateGenerator,
        private ComponentWritePolicy $writePolicy,
    ) {
    }

    /** @return array{path: string, hasTodos: bool} */
    public function create(string $variantIdentifier): array
    {
        $this->writePolicy->assertWritable();
        $metadata = $this->metadataProvider->getComponentMetadataForVariantIdentifier($variantIdentifier);
        $missing = $metadata?->missingTransformers;
        if ($missing === null || $missing->arguments === []) {
            throw new InvalidArgumentException('Select a registered component with missing argument transformers.', 1791193100);
        }

        $template = $this->templateGenerator->generate($missing->arguments);
        if (file_exists($missing->absolutePath) || is_link($missing->absolutePath)) {
            throw new RuntimeException('The transformer file already exists. Add the missing entries manually.', 1791193101);
        }

        $handle = @fopen($missing->absolutePath, 'x');
        if ($handle === false) {
            throw new RuntimeException('Could not create the transformer file exclusively. Check directory permissions or whether another request created it.', 1791193102);
        }

        $complete = false;
        try {
            if (fwrite($handle, $template['source']) !== strlen($template['source'])) {
                throw new RuntimeException('Could not write the complete transformer template.', 1791193103);
            }

            $complete = true;
        } finally {
            fclose($handle);
            if (!$complete) {
                unlink($missing->absolutePath);
            }
        }

        return ['path' => $missing->relativePath, 'hasTodos' => $template['hasTodos']];
    }
}
