<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Throwable;
use TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolverFactoryInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentTemplateResolverInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperResolverDelegateInterface;

#[AsAlias(ComponentPathResolverInterface::class)]
final readonly class ComponentPathResolver implements ComponentPathResolverInterface
{
    public function __construct(
        private ComponentResolverDelegateProvider $componentResolverDelegateProvider,
        private ViewHelperResolverFactoryInterface $viewHelperResolverFactory,
        private ComponentDiscoveryProvider $componentDiscoveryProvider,
    ) {
    }

    /**
     * @return list<string>
     */
    public function findVariantIdentifiers(string $componentPath, string $variantName): array
    {
        if ($componentPath === '' || !str_starts_with($componentPath, DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('The component path must be an absolute filesystem path.', 4197300001);
        }

        $componentDirectory = realpath(dirname($componentPath));
        if ($componentDirectory === false) {
            return [];
        }

        $namespaceAliases = $this->getFluidNamespaceAliasesByClassNamespace();
        $matches = [];
        foreach ($this->componentResolverDelegateProvider->getAll() as $classNamespace => $resolverDelegate) {
            if (!$resolverDelegate instanceof ViewHelperResolverDelegateInterface || !$resolverDelegate instanceof ComponentTemplateResolverInterface) {
                continue;
            }

            $namespace = $namespaceAliases[$classNamespace] ?? $classNamespace;
            foreach ($this->componentDiscoveryProvider->getAvailableComponents($resolverDelegate) as $componentName) {
                try {
                    $templateName = $resolverDelegate->resolveTemplateName($componentName);
                    $templatePath = $resolverDelegate->getTemplatePaths()->resolveTemplateFileForControllerAndActionAndFormat(
                        'Default',
                        $templateName,
                        null,
                        true,
                    );
                } catch (Throwable) {
                    continue;
                }

                if (!is_string($templatePath) || realpath(dirname($templatePath)) !== $componentDirectory) {
                    continue;
                }

                $matches[$namespace . ':' . $componentName . ':' . $variantName] = true;
            }
        }

        return array_keys($matches);
    }

    /**
     * @return array<string, string>
     */
    private function getFluidNamespaceAliasesByClassNamespace(): array
    {
        $aliasesByClassNamespace = [];
        foreach ($this->viewHelperResolverFactory->create()->getNamespaces() as $alias => $classNamespaces) {
            if ($classNamespaces === null) {
                continue;
            }

            foreach ($classNamespaces as $classNamespace) {
                if (!is_string($classNamespace)) {
                    continue;
                }

                $aliasesByClassNamespace[$classNamespace] ??= $alias;
            }
        }

        return $aliasesByClassNamespace;
    }
}
