<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use Throwable;
use TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolverDelegateRegistry;
use TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolverFactoryInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentDefinitionProviderInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentListProviderInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentTemplateResolverInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\ArgumentDefinition;

final readonly class ComponentTreeDataProvider
{
    private const ICONS = [
        'namespace' => 'frontend-studio-global',
        'folder' => 'frontend-studio-folder',
        'component' => 'actions-code',
        'variant' => 'actions-bookmark',
    ];

    public function __construct(
        private ViewHelperResolverDelegateRegistry $viewHelperResolverDelegateRegistry,
        private ViewHelperResolverFactoryInterface $viewHelperResolverFactory,
        private ComponentFixtureProvider $componentFixtureProvider,
    )
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTreeNodes(): array
    {
        $nodes = [];
        $componentsByNamespace = [];
        $fluidNamespaceAliases = $this->getFluidNamespaceAliasesByClassNamespace();
        $resolverDelegates = $this->viewHelperResolverDelegateRegistry->getAll();
        ksort($resolverDelegates);

        foreach ($resolverDelegates as $classNamespace => $resolverDelegate) {
            if (!$resolverDelegate instanceof ComponentListProviderInterface) {
                continue;
            }

            $components = array_values(array_unique($resolverDelegate->getAvailableComponents()));
            sort($components, SORT_NATURAL | SORT_FLAG_CASE);

            if ($components === []) {
                continue;
            }

            $namespace = $fluidNamespaceAliases[$classNamespace] ?? $classNamespace;
            foreach ($components as $component) {
                $componentsByNamespace[$namespace][$component] ??= $resolverDelegate;
            }
        }

        ksort($componentsByNamespace);

        foreach ($componentsByNamespace as $namespace => $components) {
            ksort($components, SORT_NATURAL | SORT_FLAG_CASE);

            $classNamespace = array_flip($fluidNamespaceAliases)[$namespace] ?? null;
            $name = $namespace . ':';
            if ($classNamespace !== null && $classNamespace !== $namespace) {
                $label = 'Class namespace: ' . $classNamespace;
                $name .= ' (' . $classNamespace . ')';
            } else {
                $label = 'You should set a Global Fluid namespace';
                $name .= ' (' . $label . ')';
            }
            $nodes[] = $this->createNode($namespace . ':', $name, 0, true, 'namespace', $label);
            $createdFolders = [];

            foreach ($components as $component => $resolverDelegate) {
                $fragments = explode('.', $component);
                $parentIdentifier = $namespace . ':';
                $folderFragments = array_slice($fragments, 0, -1);

                foreach ($folderFragments as $depth => $fragment) {
                    $folderIdentifier = $parentIdentifier . $fragment . '.';
                    if (!isset($createdFolders[$folderIdentifier])) {
                        $nodes[] = $this->createNode($folderIdentifier, $fragment, $depth + 1, true, 'folder');
                        $createdFolders[$folderIdentifier] = true;
                    }
                    $parentIdentifier = $folderIdentifier;
                }

                $componentIdentifier = $namespace . ':' . $component;
                $componentDepth = count($folderFragments) + 1;
                $variants = $resolverDelegate instanceof ComponentTemplateResolverInterface
                    ? $this->componentFixtureProvider->getFixtureMetadata($resolverDelegate, $component)['variants']
                    : [];

                $nodes[] = $this->createNode($componentIdentifier, $componentIdentifier, $componentDepth, $variants !== [], 'component');

                foreach ($variants as $variant) {
                    $variantName = $variant['name'];
                    $nodes[] = $this->createNode($componentIdentifier . ':' . $variantName, $variantName, $componentDepth + 1, false, 'variant');
                }
            }
        }

        return $nodes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getFilteredTreeNodes(string $searchTerm): array
    {
        $searchTerm = mb_strtolower(trim($searchTerm));
        $nodes = $this->getTreeNodes();

        if ($searchTerm === '') {
            return $nodes;
        }

        $includedIndexes = [];
        foreach ($nodes as $index => $node) {
            if (!str_contains(mb_strtolower((string)$node['name']), $searchTerm)) {
                continue;
            }

            $matchingNodeDepth = (int)$node['depth'];
            $includedIndexes[$index] = true;

            for ($descendantIndex = $index + 1, $nodeCount = count($nodes); $descendantIndex < $nodeCount; $descendantIndex++) {
                if ((int)$nodes[$descendantIndex]['depth'] <= $matchingNodeDepth) {
                    break;
                }

                $includedIndexes[$descendantIndex] = true;
            }

            for ($ancestorIndex = $index - 1, $depth = $matchingNodeDepth - 1; $ancestorIndex >= 0 && $depth >= 0; $ancestorIndex--) {
                if ((int)$nodes[$ancestorIndex]['depth'] !== $depth) {
                    continue;
                }

                $includedIndexes[$ancestorIndex] = true;
                $depth--;
            }
        }

        return array_values(
            array_filter(
                $nodes,
                static fn(array $node, int $index): bool => isset($includedIndexes[$index]),
                ARRAY_FILTER_USE_BOTH,
            ),
        );
    }

    /**
     * @return array{identifier: string, name: string, fixturePath: string, fixtureExtensionPath: string|null}
     */
    public function renameVariant(string $variantIdentifier, string $newVariantName): array
    {
        [$namespace, $componentName, $currentVariantName] = $this->parseVariantIdentifier($variantIdentifier);
        $resolverDelegate = $this->resolveComponentTemplateResolver($namespace, $componentName);
        if ($resolverDelegate === null) {
            throw new \RuntimeException('The selected component could not be resolved.');
        }

        $renameResult = $this->componentFixtureProvider->renameVariant(
            $resolverDelegate,
            $componentName,
            $currentVariantName,
            $newVariantName,
        );
        $newVariantName = trim($newVariantName);

        return [
            'identifier' => $namespace . ':' . $componentName . ':' . $newVariantName,
            'name' => $newVariantName,
            ...$renameResult,
        ];
    }

    /**
     * @return array{identifier: string, name: string, fixturePath: string, fixtureExtensionPath: string|null}
     */
    public function createVariant(string $componentIdentifier, string $variantName): array
    {
        [$namespace, $componentName] = $this->parseComponentIdentifier($componentIdentifier);
        $resolverDelegate = $this->resolveComponentTemplateResolver($namespace, $componentName);
        if ($resolverDelegate === null) {
            throw new \RuntimeException('The selected component could not be resolved.');
        }

        $createResult = $this->componentFixtureProvider->createVariant(
            $resolverDelegate,
            $componentName,
            $variantName,
            $this->getRequiredArgumentDefaults($resolverDelegate, $componentName),
        );
        $variantName = trim($variantName);

        return [
            'identifier' => $namespace . ':' . $componentName . ':' . $variantName,
            'name' => $variantName,
            ...$createResult,
        ];
    }

    /**
     * @return array{identifier: string, componentIdentifier: string, name: string, fixturePath: string, fixtureExtensionPath: string|null}
     */
    public function deleteVariant(string $variantIdentifier): array
    {
        [$namespace, $componentName, $variantName] = $this->parseVariantIdentifier($variantIdentifier);
        $resolverDelegate = $this->resolveComponentTemplateResolver($namespace, $componentName);
        if ($resolverDelegate === null) {
            throw new \RuntimeException('The selected component could not be resolved.');
        }

        $deleteResult = $this->componentFixtureProvider->deleteVariant(
            $resolverDelegate,
            $componentName,
            $variantName,
        );

        return [
            'identifier' => $variantIdentifier,
            'componentIdentifier' => $namespace . ':' . $componentName,
            'name' => $variantName,
            ...$deleteResult,
        ];
    }

    /**
     * @param array<string, mixed> $variantValues
     * @return array{identifier: string, componentIdentifier: string, name: string, fixturePath: string, fixtureExtensionPath: string|null, values: array<string, mixed>}
     */
    public function updateVariantValues(string $variantIdentifier, array $variantValues): array
    {
        [$namespace, $componentName, $variantName] = $this->parseVariantIdentifier($variantIdentifier);
        $resolverDelegate = $this->resolveComponentTemplateResolver($namespace, $componentName);
        if ($resolverDelegate === null) {
            throw new \RuntimeException('The selected component could not be resolved.');
        }

        $updateResult = $this->componentFixtureProvider->updateVariantValues(
            $resolverDelegate,
            $componentName,
            $variantName,
            $variantValues,
        );

        return [
            'identifier' => $variantIdentifier,
            'componentIdentifier' => $namespace . ':' . $componentName,
            'name' => $variantName,
            ...$updateResult,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createNode(string $identifier, string $name, int $depth, bool $hasChildren, string $nodeType, ?string $label = null): array
    {
        $labels = [];
        if ($label) {
            $labels[] = [
                'label' => $label,
                'color' => '',
                'priority' => 0,
                'inheritByChildren' => false,
            ];
        }
        return [
            'identifier' => $identifier,
            'name' => $name,
            'depth' => $depth,
            'hasChildren' => $hasChildren,
            'editable' => $nodeType === 'variant',
            'loaded' => true,
            'icon' => $this->getIconForNode($nodeType, $name),
            'nodeType' => $nodeType,
            'labels' => $labels,
        ];
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
                $aliasesByClassNamespace[$classNamespace] ??= $alias;
            }
        }

        return $aliasesByClassNamespace;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function parseVariantIdentifier(string $variantIdentifier): array
    {
        $identifierParts = explode(':', trim($variantIdentifier), 3);
        if (count($identifierParts) !== 3) {
            throw new \InvalidArgumentException('The variant identifier is invalid.');
        }

        [$namespace, $componentName, $variantName] = $identifierParts;
        if ($namespace === '' || $componentName === '' || $variantName === '') {
            throw new \InvalidArgumentException('The variant identifier is invalid.');
        }

        return [$namespace, $componentName, $variantName];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseComponentIdentifier(string $componentIdentifier): array
    {
        $identifierParts = explode(':', trim($componentIdentifier), 2);
        if (count($identifierParts) !== 2) {
            throw new \InvalidArgumentException('The component identifier is invalid.');
        }

        [$namespace, $componentName] = $identifierParts;
        if ($namespace === '' || $componentName === '') {
            throw new \InvalidArgumentException('The component identifier is invalid.');
        }

        return [$namespace, $componentName];
    }

    private function resolveComponentTemplateResolver(string $namespace, string $componentName): ?ComponentTemplateResolverInterface
    {
        $fluidNamespaceAliases = $this->getFluidNamespaceAliasesByClassNamespace();
        $resolverDelegates = $this->viewHelperResolverDelegateRegistry->getAll();
        ksort($resolverDelegates);

        foreach ($resolverDelegates as $classNamespace => $resolverDelegate) {
            if (!$resolverDelegate instanceof ComponentListProviderInterface || !$resolverDelegate instanceof ComponentTemplateResolverInterface) {
                continue;
            }

            $displayNamespace = $fluidNamespaceAliases[$classNamespace] ?? $classNamespace;
            if ($displayNamespace !== $namespace) {
                continue;
            }

            if (!in_array($componentName, $resolverDelegate->getAvailableComponents(), true)) {
                continue;
            }

            return $resolverDelegate;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function getRequiredArgumentDefaults(ComponentTemplateResolverInterface $resolverDelegate, string $componentName): array
    {
        if (!$resolverDelegate instanceof ComponentDefinitionProviderInterface) {
            return [];
        }

        try {
            $argumentDefinitions = $resolverDelegate->getComponentDefinition($componentName)->getArgumentDefinitions();
        } catch (Throwable) {
            return [];
        }

        $defaults = [];
        foreach ($argumentDefinitions as $argumentDefinition) {
            if (!$argumentDefinition instanceof ArgumentDefinition || !$argumentDefinition->isRequired()) {
                continue;
            }

            $defaults[$argumentDefinition->getName()] = $this->getDefaultValueForArgumentType($argumentDefinition->getType());
        }

        return $defaults;
    }

    private function getDefaultValueForArgumentType(string $type): mixed
    {
        $normalizedType = strtolower($type);

        if (preg_match('/\bbool(ean)?\b/', $normalizedType)) {
            return false;
        }
        if (preg_match('/\bint(eger)?\b/', $normalizedType)) {
            return 0;
        }
        if (preg_match('/\b(float|double)\b/', $normalizedType)) {
            return 0.0;
        }
        if (preg_match('/\b(array|iterable|list|map)\b/', $normalizedType)) {
            return [];
        }
        if ($normalizedType === '' || preg_match('/\b(string|scalar|mixed)\b/', $normalizedType)) {
            return 'Lorem ipsum';
        }

        return null;
    }

    private function getIconForNode(string $nodeType, string $name): string
    {
        if (preg_match('/^[._]?(views?|templates?|pages?|html)$/i', $name)) {
            return 'frontend-studio-views';
        }
        if (preg_match('/^[._]?(redux|organisms?|flux)$/i', $name)) {
            return 'frontend-studio-redux';
        }
        if (preg_match('/^[._]?atoms?(-ci)?$/i', $name)) {
            return 'frontend-studio-atoms';
        }
        if (preg_match('/^[._]?molecules?$/i', $name)) {
            return 'frontend-studio-molecules';
        }
        return self::ICONS[$nodeType];
    }
}
