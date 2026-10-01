<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use InvalidArgumentException;
use RuntimeException;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantValues;
use Andersundsehr\FrontendStudio\Transformer\TransformersFactory;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextFactory;
use TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolverFactoryInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentDefinitionProviderInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentTemplateResolverInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperResolverDelegateInterface;

final readonly class ComponentPreviewRenderer implements ComponentPreviewRendererInterface
{
    public function __construct(
        private ComponentResolverDelegateProvider $componentResolverDelegateProvider,
        private ViewHelperResolverFactoryInterface $viewHelperResolverFactory,
        private RenderingContextFactory $renderingContextFactory,
        private ComponentFixtureProvider $componentFixtureProvider,
        private ComponentDiscoveryProvider $componentDiscoveryProvider,
        private TransformersFactory $transformersFactory,
        private ComponentVariantTransformer $componentVariantTransformer,
        private AssetCollector $assetCollector,
    ) {
    }

    /**
     * @param array<string, string>|null $slotOverrides
     */
    public function renderVariant(string $variantIdentifier, ServerRequestInterface $request, ?ComponentVariantValues $variantValueOverrides = null, ?array $slotOverrides = null): string
    {
        [$namespace, $componentName, $variantName] = $this->parseVariantIdentifier($variantIdentifier);
        $resolverDelegate = $this->resolveComponent($namespace, $componentName);
        $variantValues = $variantValueOverrides
            ?? $this->componentFixtureProvider->getVariantValues($resolverDelegate, $componentName, $variantName);
        foreach ($this->componentFixtureProvider->getPreviewStylesheets($resolverDelegate, $componentName) as $index => $stylesheet) {
            $this->assetCollector->addStyleSheet('frontend-studio-fixture-stylesheet-' . $index, $stylesheet);
        }

        $renderingContext = $this->renderingContextFactory->create([], $request);

        $componentDefinition = $resolverDelegate->getComponentDefinition($componentName);
        $values = $this->componentVariantTransformer->transform(
            $componentDefinition,
            $this->transformersFactory->get($resolverDelegate, $componentName),
            $variantValues->toArray(),
        );
        $storedSlotValues = $this->componentFixtureProvider->getVariantSlots(
            $resolverDelegate,
            $componentName,
            $variantName,
            array_values($componentDefinition->getAvailableSlots()),
        );
        $slotValues = $slotOverrides ?? $storedSlotValues;
        $slots = [];
        foreach ($slotValues as $slotName => $slotValue) {
            $slots[$slotName] = static fn(): string => $slotValue;
        }

        return trim($resolverDelegate->getComponentRenderer()->renderComponent(
            $componentName,
            $values,
            $slots,
            $renderingContext,
        ));
    }

    /**
     * @return array{string, string, string}
     */
    private function parseVariantIdentifier(string $variantIdentifier): array
    {
        $identifierParts = explode(':', trim($variantIdentifier), 3);
        if (count($identifierParts) !== 3) {
            throw new InvalidArgumentException('The variant identifier is invalid.', 2846232392);
        }

        [$namespace, $componentName, $variantName] = $identifierParts;
        if ($namespace === '' || $componentName === '' || $variantName === '') {
            throw new InvalidArgumentException('The variant identifier is invalid.', 7380533273);
        }

        return [$namespace, $componentName, $variantName];
    }

    private function resolveComponent(string $namespace, string $componentName): ViewHelperResolverDelegateInterface&ComponentDefinitionProviderInterface&ComponentTemplateResolverInterface
    {
        $fluidNamespaceAliases = $this->getFluidNamespaceAliasesByClassNamespace();
        $resolverDelegates = $this->componentResolverDelegateProvider->getAll();
        ksort($resolverDelegates);

        foreach ($resolverDelegates as $classNamespace => $resolverDelegate) {
            if (
                !$resolverDelegate instanceof ViewHelperResolverDelegateInterface
                || !$resolverDelegate instanceof ComponentDefinitionProviderInterface
                || !$resolverDelegate instanceof ComponentTemplateResolverInterface
            ) {
                continue;
            }

            $displayNamespace = $fluidNamespaceAliases[$classNamespace] ?? $classNamespace;
            if ($displayNamespace !== $namespace) {
                continue;
            }

            if (!$this->componentDiscoveryProvider->hasComponent($resolverDelegate, $componentName)) {
                continue;
            }

            return $resolverDelegate;
        }

        throw new RuntimeException('The selected component could not be resolved.', 9185246890);
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
