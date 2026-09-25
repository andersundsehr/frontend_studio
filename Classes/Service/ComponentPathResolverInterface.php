<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

interface ComponentPathResolverInterface
{
    /**
     * @return list<string>
     */
    public function findVariantIdentifiers(string $componentPath, string $variantName): array;
}
