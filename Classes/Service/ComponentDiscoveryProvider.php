<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\Component\AbstractComponentCollection;
use TYPO3Fluid\Fluid\Core\Component\ComponentListProviderInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentTemplateResolverInterface;

final readonly class ComponentDiscoveryProvider
{
    /**
     * @return list<string>
     */
    public function getAvailableComponents(object $resolverDelegate): array
    {
        if ($resolverDelegate instanceof ComponentListProviderInterface) {
            return $this->normalizeComponentNames($resolverDelegate->getAvailableComponents());
        }

        return [];
    }

    public function hasComponent(object $resolverDelegate, string $componentName): bool
    {
        return in_array($componentName, $this->getAvailableComponents($resolverDelegate), true);
    }

    /**
     * @param list<string>|array<string> $componentNames
     * @return list<string>
     */
    private function normalizeComponentNames(array $componentNames): array
    {
        $normalized = [];
        foreach ($componentNames as $componentName) {
            if (!is_string($componentName) || $componentName === '') {
                continue;
            }

            $normalized[$componentName] = true;
        }

        $componentNames = array_keys($normalized);
        sort($componentNames, SORT_NATURAL | SORT_FLAG_CASE);

        return $componentNames;
    }
}
