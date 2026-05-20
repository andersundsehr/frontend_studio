<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use Throwable;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Package\PackageInterface;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolverDelegateRegistry;
use TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolverFactoryInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentDefinitionProviderInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentListProviderInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentTemplateResolverInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\ArgumentDefinition;

final readonly class ComponentMetadataProvider
{
    public function __construct(
        private ViewHelperResolverDelegateRegistry $viewHelperResolverDelegateRegistry,
        private ViewHelperResolverFactoryInterface $viewHelperResolverFactory,
        private PackageManager $packageManager,
        private ComponentFixtureProvider $componentFixtureProvider,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function getComponentMetadataForVariantIdentifier(string $variantIdentifier): ?array
    {
        $variantIdentifier = trim($variantIdentifier);
        if ($variantIdentifier === '') {
            return null;
        }

        $identifierParts = explode(':', $variantIdentifier, 3);
        if (count($identifierParts) !== 3) {
            return null;
        }

        [$namespace, $componentName, $variantName] = $identifierParts;
        if ($namespace === '' || $componentName === '' || $variantName === '') {
            return null;
        }

        $component = $this->resolveComponent($namespace, $componentName);
        if ($component === null) {
            return null;
        }

        $metadata = [
            'selectedVariantIdentifier' => $variantIdentifier,
            'variantName' => $variantName,
            'variantLabel' => $this->getVariantLabel($variantName),
            'componentIdentifier' => $namespace . ':' . $componentName,
            'componentName' => $componentName,
            'displayNamespace' => $namespace,
            'sourceNamespace' => $component['sourceNamespace'],
            'usesNamespaceAlias' => $namespace !== $component['sourceNamespace'],
            'collectionClass' => $component['collectionClass'],
            'arguments' => [],
            'additionalArgumentsAllowed' => false,
            'slots' => [],
            'annotations' => [],
            'template' => null,
            'fixture' => null,
            'staticVariables' => [],
            'errors' => [],
        ];

        $resolverDelegate = $component['resolverDelegate'];
        if ($resolverDelegate instanceof ComponentDefinitionProviderInterface) {
            try {
                $componentDefinition = $resolverDelegate->getComponentDefinition($componentName);
                $metadata['arguments'] = $this->normalizeArgumentDefinitions($componentDefinition->getArgumentDefinitions());
                $metadata['additionalArgumentsAllowed'] = $componentDefinition->additionalArgumentsAllowed();
                $metadata['slots'] = array_values($componentDefinition->getAvailableSlots());
                $metadata['annotations'] = array_map(
                    static fn(object $annotation): string => $annotation::class,
                    $componentDefinition->getAnnotations(),
                );
            } catch (Throwable $throwable) {
                $metadata['errors'][] = 'Component definition could not be loaded: ' . $throwable->getMessage();
            }
        } else {
            $metadata['errors'][] = 'The component collection does not provide component definitions.';
        }

        if ($resolverDelegate instanceof ComponentTemplateResolverInterface) {
            $metadata['template'] = $this->getTemplateMetadata($resolverDelegate, $componentName);
            $metadata['fixture'] = $this->getFixtureMetadata($resolverDelegate, $componentName, $variantName);
            if ($metadata['fixture']['error'] !== null) {
                $metadata['errors'][] = 'Fixture file could not be loaded: ' . $metadata['fixture']['error'];
            } elseif ($metadata['fixture']['variants'] !== [] && $metadata['fixture']['selectedVariant'] === null) {
                $metadata['errors'][] = 'Selected variant was not found in the fixture file.';
            }
            if ($metadata['fixture']['selectedVariant'] !== null) {
                $metadata['fixture']['selectedVariant']['values'] = $this->mergeVariantValuesWithArguments(
                    $metadata['fixture']['selectedVariant']['values'],
                    $metadata['arguments'],
                );
            }
            try {
                $metadata['staticVariables'] = $this->normalizeStaticVariables(
                    $resolverDelegate->getAdditionalVariables($componentName),
                );
            } catch (Throwable $throwable) {
                $metadata['errors'][] = 'Static variables could not be loaded: ' . $throwable->getMessage();
            }
        } else {
            $metadata['errors'][] = 'The component collection does not provide template metadata.';
        }

        return $metadata;
    }

    /**
     * @param list<array{name: string, type: string, value: string, isMultiline?: bool, isFixtureValue?: bool}> $variantValues
     * @param list<array<string, mixed>> $arguments
     * @return list<array{name: string, type: string, value: string, isMultiline: bool, isFixtureValue: bool}>
     */
    private function mergeVariantValuesWithArguments(array $variantValues, array $arguments): array
    {
        $variantValuesByName = [];
        foreach ($variantValues as $variantValue) {
            $variantValuesByName[$variantValue['name']] = [
                ...$variantValue,
                'isMultiline' => (bool)($variantValue['isMultiline'] ?? str_contains($variantValue['value'], "\n")),
                'isFixtureValue' => (bool)($variantValue['isFixtureValue'] ?? true),
            ];
        }

        $mergedValues = [];
        foreach ($arguments as $argument) {
            $name = isset($argument['name']) ? (string)$argument['name'] : '';
            if ($name === '') {
                continue;
            }

            if (isset($variantValuesByName[$name])) {
                $mergedValues[] = $variantValuesByName[$name];
                unset($variantValuesByName[$name]);
                continue;
            }

            $value = isset($argument['defaultValue']) ? (string)$argument['defaultValue'] : '';
            $mergedValues[] = [
                'name' => $name,
                'type' => isset($argument['type']) ? (string)$argument['type'] : 'string',
                'value' => $value,
                'isMultiline' => str_contains($value, "\n"),
                'isFixtureValue' => false,
            ];
        }

        return [
            ...$mergedValues,
            ...array_values($variantValuesByName),
        ];
    }

    /**
     * @return array{sourceNamespace: string, collectionClass: class-string, resolverDelegate: object}|null
     */
    private function resolveComponent(string $namespace, string $componentName): ?array
    {
        $fluidNamespaceAliases = $this->getFluidNamespaceAliasesByClassNamespace();
        $resolverDelegates = $this->viewHelperResolverDelegateRegistry->getAll();
        ksort($resolverDelegates);

        foreach ($resolverDelegates as $classNamespace => $resolverDelegate) {
            if (!$resolverDelegate instanceof ComponentListProviderInterface) {
                continue;
            }

            $displayNamespace = $fluidNamespaceAliases[$classNamespace] ?? $classNamespace;
            if ($displayNamespace !== $namespace) {
                continue;
            }

            if (!in_array($componentName, $resolverDelegate->getAvailableComponents(), true)) {
                continue;
            }

            return [
                'sourceNamespace' => $classNamespace,
                'collectionClass' => $resolverDelegate::class,
                'resolverDelegate' => $resolverDelegate,
            ];
        }

        return null;
    }

    /**
     * @param array<string, ArgumentDefinition> $argumentDefinitions
     * @return list<array<string, mixed>>
     */
    private function normalizeArgumentDefinitions(array $argumentDefinitions): array
    {
        $arguments = [];
        foreach ($argumentDefinitions as $argumentDefinition) {
            $arguments[] = [
                'name' => $argumentDefinition->getName(),
                'type' => $argumentDefinition->getType(),
                'description' => $argumentDefinition->getDescription(),
                'required' => $argumentDefinition->isRequired(),
                'defaultValue' => $this->normalizeValue($argumentDefinition->getDefaultValue()),
                'escape' => match ($argumentDefinition->getEscape()) {
                    true => 'enabled',
                    false => 'disabled',
                    null => 'default',
                },
                'annotations' => array_map(
                    static fn(object $annotation): string => $annotation::class,
                    $argumentDefinition->getAnnotations(),
                ),
            ];
        }

        return $arguments;
    }

    /**
     * @return array<string, mixed>
     */
    private function getTemplateMetadata(ComponentTemplateResolverInterface $resolverDelegate, string $componentName): array
    {
        $templateName = $resolverDelegate->resolveTemplateName($componentName);
        $templatePaths = $resolverDelegate->getTemplatePaths();
        $absolutePath = null;
        $content = null;
        $error = null;

        try {
            $absolutePath = $templatePaths->resolveTemplateFileForControllerAndActionAndFormat(
                'Default',
                $templateName,
                null,
                true,
            );
            $content = $absolutePath !== null ? file_get_contents($absolutePath) : null;
            if ($content === false) {
                $content = null;
                $error = 'Template file could not be read.';
            }
        } catch (Throwable $throwable) {
            $error = $throwable->getMessage();
        }

        return [
            'name' => $templateName,
            'absolutePath' => $absolutePath,
            'extensionPath' => is_string($absolutePath) ? $this->getExtensionPath($absolutePath) : null,
            'relativePath' => is_string($absolutePath) ? $this->getRelativePath($absolutePath) : null,
            'rootPaths' => $this->normalizeTemplateRootPaths($templatePaths->getTemplateRootPaths()),
            'content' => $content,
            'error' => $error,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getFixtureMetadata(ComponentTemplateResolverInterface $resolverDelegate, string $componentName, string $variantName): array
    {
        $fixture = $this->componentFixtureProvider->getFixtureMetadata($resolverDelegate, $componentName);
        $selectedVariant = null;

        foreach ($fixture['variants'] as $variant) {
            if ($variant['name'] !== $variantName) {
                continue;
            }

            $selectedVariant = $variant;
            break;
        }

        return [
            ...$fixture,
            'selectedVariant' => $selectedVariant,
        ];
    }

    /**
     * @param array<string|int, string> $templateRootPaths
     * @return list<array{priority: string|int, absolutePath: string, extensionPath: string|null}>
     */
    private function normalizeTemplateRootPaths(array $templateRootPaths): array
    {
        $rootPaths = [];
        foreach ($templateRootPaths as $priority => $rootPath) {
            $rootPaths[] = [
                'priority' => $priority,
                'absolutePath' => $rootPath,
                'extensionPath' => $this->getExtensionPath($rootPath),
            ];
        }

        return $rootPaths;
    }

    /**
     * @param array<string, mixed> $staticVariables
     * @return list<array{name: string, type: string, value: string}>
     */
    private function normalizeStaticVariables(array $staticVariables): array
    {
        $variables = [];
        foreach ($staticVariables as $name => $value) {
            $variables[] = [
                'name' => (string)$name,
                'type' => get_debug_type($value),
                'value' => $this->normalizeValue($value),
            ];
        }

        return $variables;
    }

    private function normalizeValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        if (is_object($value)) {
            return 'object(' . $value::class . ')';
        }

        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (Throwable) {
            return get_debug_type($value);
        }
    }

    private function getExtensionPath(string $absolutePath): ?string
    {
        $absolutePath = rtrim($absolutePath, '/');
        foreach ($this->packageManager->getActivePackages() as $package) {
            if (!$package instanceof PackageInterface) {
                continue;
            }

            $packagePath = rtrim($package->getPackagePath(), '/');
            if ($packagePath === '' || ($absolutePath !== $packagePath && !str_starts_with($absolutePath, $packagePath . '/'))) {
                continue;
            }

            $relativePath = ltrim(substr($absolutePath, strlen($packagePath)), '/');
            return 'EXT:' . $package->getPackageKey() . ($relativePath !== '' ? '/' . $relativePath : '');
        }

        return null;
    }

    private function getRelativePath(string $absolutePath): ?string
    {
        $absolutePath = rtrim($absolutePath, '/');
        $projectPath = rtrim(Environment::getProjectPath(), '/');
        if ($projectPath !== '' && ($absolutePath === $projectPath || str_starts_with($absolutePath, $projectPath . '/'))) {
            return ltrim(substr($absolutePath, strlen($projectPath)), '/');
        }

        return $this->getExtensionPath($absolutePath);
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

    private function getVariantLabel(string $variantName): string
    {
        return $variantName;
    }
}
