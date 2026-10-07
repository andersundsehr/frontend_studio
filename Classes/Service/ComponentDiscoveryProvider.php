<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

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
            $components = $this->normalizeComponentNames($resolverDelegate->getAvailableComponents());
            if ($resolverDelegate instanceof ComponentTemplateResolverInterface) {
                return array_values(array_filter(
                    $components,
                    fn(string $component): bool => !$this->isSnapshotTemplate($resolverDelegate, $component),
                ));
            }

            return $components;
        }

        return [];
    }

    public function hasComponent(object $resolverDelegate, string $componentName): bool
    {
        return in_array($componentName, $this->getAvailableComponents($resolverDelegate), true);
    }

    private function isSnapshotTemplate(ComponentTemplateResolverInterface $resolverDelegate, string $component): bool
    {
        $templateName = $resolverDelegate->resolveTemplateName($component);
        $templatePath = $resolverDelegate->getTemplatePaths()->resolveTemplateFileForControllerAndActionAndFormat('Default', $templateName);

        return preg_match('~(?:^|/)[^/]*-snapshots/~', str_replace('\\', '/', $templatePath ?? $templateName)) === 1;
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
