<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use RuntimeException;
use DateTimeImmutable;
use InvalidArgumentException;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantValues;
use Andersundsehr\FrontendStudio\Transformer\Transformer;
use Andersundsehr\FrontendStudio\Transformer\TransformersFactory;
use Throwable;
use TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolverFactoryInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentDefinitionProviderInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentTemplateResolverInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\ArgumentDefinition;

use function enum_exists;

final readonly class ComponentTreeDataProvider
{
    private const array ICONS = [
        'namespace' => 'frontend-studio-global',
        'folder' => 'frontend-studio-folder',
        'component' => 'actions-code',
        'variant' => 'actions-bookmark',
    ];

    public function __construct(
        private ComponentResolverDelegateProvider $componentResolverDelegateProvider,
        private ViewHelperResolverFactoryInterface $viewHelperResolverFactory,
        private ComponentFixtureProvider $componentFixtureProvider,
        private ComponentDiscoveryProvider $componentDiscoveryProvider,
        private TransformersFactory $transformersFactory,
        private ComponentWritePolicy $writePolicy = new ComponentWritePolicy(),
    ) {
    }

    /**
     * Build the component and fixture variant tree.
     *
     * @param bool $strict Throw on fixture metadata errors instead of keeping the
     *                     component visible without its invalid variants. The UI
     *                     uses false to keep the tree available; snapshot discovery
     *                     uses true so invalid fixtures cannot silently skip tests.
     *                     Validation covers the entire tree before scope selection.
     * @return list<array<string, mixed>>
     * @throws RuntimeException When strict discovery encounters a fixture error.
     */
    public function getTreeNodes(bool $strict = false): array
    {
        $nodes = [];
        $componentsByNamespace = [];
        $fluidNamespaceAliases = $this->getFluidNamespaceAliasesByClassNamespace();
        $resolverDelegates = $this->componentResolverDelegateProvider->getAll();
        ksort($resolverDelegates);

        foreach ($resolverDelegates as $classNamespace => $resolverDelegate) {
            $components = $this->componentDiscoveryProvider->getAvailableComponents($resolverDelegate);
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
                $fixture = $resolverDelegate instanceof ComponentTemplateResolverInterface
                    ? $this->componentFixtureProvider->getFixtureMetadata($resolverDelegate, $component)
                    : null;
                if ($strict && $fixture?->error !== null) {
                    throw new RuntimeException($componentIdentifier . ': ' . $fixture->error, 6712648841);
                }

                $variants = $fixture->variants ?? [];

                $nodes[] = $this->createNode($componentIdentifier, $componentIdentifier, $componentDepth, $variants !== [], 'component');

                foreach ($variants as $variant) {
                    $variantName = $variant->name;
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
            throw new RuntimeException('The selected component could not be resolved.', 6749987960);
        }

        $renameResult = $this->componentFixtureProvider->renameVariant(
            $resolverDelegate,
            $componentName,
            $currentVariantName,
            $newVariantName,
            $this->getSlotNames($resolverDelegate, $componentName),
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
            throw new RuntimeException('The selected component could not be resolved.', 3592001973);
        }

        $createResult = $this->componentFixtureProvider->createVariant(
            $resolverDelegate,
            $componentName,
            $variantName,
            $this->getRequiredArgumentDefaults($resolverDelegate, $componentName),
            $this->getSlotNames($resolverDelegate, $componentName),
        );
        $variantName = trim($variantName);

        return [
            'identifier' => $namespace . ':' . $componentName . ':' . $variantName,
            'name' => $variantName,
            ...$createResult,
        ];
    }

    /**
     * @param array<string, mixed>|null $slotValues
     * @return array{identifier: string, componentIdentifier: string, name: string, fixturePath: string, fixtureExtensionPath: string|null}
     */
    public function copyVariant(string $sourceVariantIdentifier, string $newVariantName, ?ComponentVariantValues $variantValues = null, ?array $slotValues = null): array
    {
        [$namespace, $componentName, $sourceVariantName] = $this->parseVariantIdentifier($sourceVariantIdentifier);
        $resolverDelegate = $this->resolveComponentTemplateResolver($namespace, $componentName);
        if ($resolverDelegate === null) {
            throw new RuntimeException('The selected component could not be resolved.', 9264046266);
        }

        $argumentTypes = $this->getArgumentTypes($resolverDelegate, $componentName);
        $copyResult = $this->componentFixtureProvider->copyVariant(
            $resolverDelegate,
            $componentName,
            $sourceVariantName,
            $newVariantName,
            $variantValues?->normalizeForArgumentTypes($argumentTypes),
            $argumentTypes,
            $slotValues,
            $this->getSlotNames($resolverDelegate, $componentName),
        );
        $newVariantName = trim($newVariantName);

        return [
            'identifier' => $namespace . ':' . $componentName . ':' . $newVariantName,
            'componentIdentifier' => $namespace . ':' . $componentName,
            'name' => $newVariantName,
            ...$copyResult,
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
            throw new RuntimeException('The selected component could not be resolved.', 1549498001);
        }

        $deleteResult = $this->componentFixtureProvider->deleteVariant(
            $resolverDelegate,
            $componentName,
            $variantName,
            $this->getSlotNames($resolverDelegate, $componentName),
        );

        return [
            'identifier' => $variantIdentifier,
            'componentIdentifier' => $namespace . ':' . $componentName,
            'name' => $variantName,
            ...$deleteResult,
        ];
    }

    /**
     * @param array<string, mixed>|null $slotValues
     * @return array{identifier: string, componentIdentifier: string, name: string, fixturePath: string, fixtureExtensionPath: string|null, values: array<string, mixed>}
     */
    public function updateVariantValues(string $variantIdentifier, ComponentVariantValues $variantValues, ?array $slotValues = null): array
    {
        [$namespace, $componentName, $variantName] = $this->parseVariantIdentifier($variantIdentifier);
        $resolverDelegate = $this->resolveComponentTemplateResolver($namespace, $componentName);
        if ($resolverDelegate === null) {
            throw new RuntimeException('The selected component could not be resolved.', 7084891228);
        }

        $updateResult = $this->componentFixtureProvider->updateVariantValues(
            $resolverDelegate,
            $componentName,
            $variantName,
            $variantValues->normalizeForArgumentTypes($this->getArgumentTypes($resolverDelegate, $componentName)),
            $slotValues,
            $this->getSlotNames($resolverDelegate, $componentName),
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
            'editable' => $nodeType === 'variant' && !$this->writePolicy->isReadOnly(),
            'readOnly' => $this->writePolicy->isReadOnly(),
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
                if (!is_string($classNamespace)) {
                    continue;
                }

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
            throw new InvalidArgumentException('The variant identifier is invalid.', 5044672354);
        }

        [$namespace, $componentName, $variantName] = $identifierParts;
        if ($namespace === '' || $componentName === '' || $variantName === '') {
            throw new InvalidArgumentException('The variant identifier is invalid.', 2259431300);
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
            throw new InvalidArgumentException('The component identifier is invalid.', 5523922312);
        }

        [$namespace, $componentName] = $identifierParts;
        if ($namespace === '' || $componentName === '') {
            throw new InvalidArgumentException('The component identifier is invalid.', 2917257212);
        }

        return [$namespace, $componentName];
    }

    private function resolveComponentTemplateResolver(string $namespace, string $componentName): ?ComponentTemplateResolverInterface
    {
        $fluidNamespaceAliases = $this->getFluidNamespaceAliasesByClassNamespace();
        $resolverDelegates = $this->componentResolverDelegateProvider->getAll();
        ksort($resolverDelegates);

        foreach ($resolverDelegates as $classNamespace => $resolverDelegate) {
            if (!$resolverDelegate instanceof ComponentTemplateResolverInterface) {
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
            $transformers = $this->transformersFactory->get($resolverDelegate, $componentName);
        } catch (Throwable) {
            return [];
        }

        $defaults = [];
        foreach ($argumentDefinitions as $argumentDefinition) {
            if (!$argumentDefinition instanceof ArgumentDefinition || !$argumentDefinition->isRequired()) {
                continue;
            }

            $transformer = $transformers->arguments[$argumentDefinition->getName()] ?? null;
            $defaults[$argumentDefinition->getName()] = $transformer === null
                ? $this->getDefaultValueForArgumentType($argumentDefinition->getType())
                : $this->getTransformerDefaults($transformer);
        }

        return $defaults;
    }

    /**
     * @return array<string, mixed>
     */
    private function getTransformerDefaults(Transformer $transformer): array
    {
        $defaults = [];
        foreach ($transformer->arguments as $argument) {
            $defaults[$argument->getName()] = $argument->isRequired()
                ? $this->getDefaultValueForArgumentType($argument->getType())
                : $argument->getDefaultValue();
        }

        return $defaults;
    }

    /**
     * @return array<string, string>
     */
    private function getArgumentTypes(ComponentTemplateResolverInterface $resolverDelegate, string $componentName): array
    {
        if (!$resolverDelegate instanceof ComponentDefinitionProviderInterface) {
            return [];
        }

        try {
            $argumentDefinitions = $resolverDelegate->getComponentDefinition($componentName)->getArgumentDefinitions();
            $transformers = $this->transformersFactory->get($resolverDelegate, $componentName);
        } catch (Throwable) {
            return [];
        }

        $types = [];
        foreach ($argumentDefinitions as $argumentDefinition) {
            if (!$argumentDefinition instanceof ArgumentDefinition) {
                continue;
            }

            $types[$argumentDefinition->getName()] = $argumentDefinition->getType();
            $transformer = $transformers->arguments[$argumentDefinition->getName()] ?? null;
            if ($transformer === null) {
                continue;
            }

            foreach ($transformer->arguments as $inputName => $inputDefinition) {
                $types[$argumentDefinition->getName() . '.' . $inputName] = $inputDefinition->getType();
            }
        }

        return $types;
    }

    /**
     * @return list<string>
     */
    private function getSlotNames(ComponentTemplateResolverInterface $resolverDelegate, string $componentName): array
    {
        if (!$resolverDelegate instanceof ComponentDefinitionProviderInterface) {
            return [];
        }

        return array_values($resolverDelegate->getComponentDefinition($componentName)->getAvailableSlots());
    }

    private function getDefaultValueForArgumentType(string $type): mixed
    {
        if ($type === DateTimeImmutable::class) {
            return new DateTimeImmutable()->format('Y-m-d\\TH:i');
        }

        if (enum_exists($type)) {
            return $type::cases()[0] ?? null;
        }

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
