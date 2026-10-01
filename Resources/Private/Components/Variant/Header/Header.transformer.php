<?php

declare(strict_types=1);

use Andersundsehr\FrontendStudio\Dto\ComponentMetadata;
use Andersundsehr\FrontendStudio\Service\ComponentMetadataProvider;
use Andersundsehr\FrontendStudio\Transformer\ArgumentTransformers;

return new ArgumentTransformers(
    selectedComponentMetadata: static fn(ComponentMetadataProvider $metadataProvider, string $variantIdentifier): ComponentMetadata =>
        $metadataProvider->getComponentMetadataForVariantIdentifier($variantIdentifier)
        ?? throw new RuntimeException('The fixture component variant could not be resolved.', 4603252272),
);
