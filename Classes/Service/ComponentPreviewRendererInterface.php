<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use Andersundsehr\FrontendStudio\Dto\ComponentVariantValues;
use Psr\Http\Message\ServerRequestInterface;

interface ComponentPreviewRendererInterface
{
    /**
     * @param array<string, string>|null $slotOverrides
     */
    public function renderVariant(string $variantIdentifier, ServerRequestInterface $request, ?ComponentVariantValues $variantValueOverrides = null, ?array $slotOverrides = null): string;
}
