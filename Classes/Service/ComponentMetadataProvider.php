<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use DateTime;
use DateTimeInterface;
use Andersundsehr\FrontendStudio\ValueFormatter;
use Andersundsehr\FrontendStudio\Dto\ComponentArgumentMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentFixtureMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentStaticVariableMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentTemplateMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentTemplateRootPathMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantValueMetadata;
use Andersundsehr\FrontendStudio\Transformer\Transformers;
use Andersundsehr\FrontendStudio\Transformer\TransformersFactory;
use DateTimeImmutable;
use Throwable;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolverFactoryInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentDefinitionProviderInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentTemplateResolverInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\ArgumentDefinition;

final readonly class ComponentMetadataProvider
{
    public function __construct(
        private ComponentResolverDelegateProvider $componentResolverDelegateProvider,
        private ViewHelperResolverFactoryInterface $viewHelperResolverFactory,
        private PackageManager $packageManager,
        private ComponentFixtureProvider $componentFixtureProvider,
        private ComponentDiscoveryProvider $componentDiscoveryProvider,
        private TransformersFactory $transformersFactory,
    ) {
    }

    public function getComponentMetadataForVariantIdentifier(string $variantIdentifier): ?ComponentMetadata
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

        $arguments = [];
        $additionalArgumentsAllowed = false;
        $slots = [];
        $annotations = [];
        $template = null;
        $fixture = null;
        $staticVariables = [];
        $errors = [];
        $transformers = null;
        $resolverDelegate = $component['resolverDelegate'];

        if ($resolverDelegate instanceof ComponentDefinitionProviderInterface) {
            try {
                $componentDefinition = $resolverDelegate->getComponentDefinition($componentName);
                $arguments = $this->normalizeArgumentDefinitions($componentDefinition->getArgumentDefinitions());
                $additionalArgumentsAllowed = $componentDefinition->additionalArgumentsAllowed();
                $slots = array_values($componentDefinition->getAvailableSlots());
                $annotations = array_values(array_map(static fn(object $annotation): string => $annotation::class, $componentDefinition->getAnnotations()));
            } catch (Throwable $throwable) {
                $errors[] = 'Component definition could not be loaded: ' . $throwable->getMessage();
            }
        } else {
            $errors[] = 'The component collection does not provide component definitions.';
        }

        if ($resolverDelegate instanceof ComponentTemplateResolverInterface) {
            $template = $this->getTemplateMetadata($resolverDelegate, $componentName);
            $fixture = $this->getFixtureMetadata($resolverDelegate, $componentName, $variantName);
            if ($fixture->error !== null) {
                $errors[] = 'Fixture file could not be loaded: ' . $fixture->error;
            } elseif ($fixture->variants !== [] && $fixture->selectedVariant === null) {
                $errors[] = 'Selected variant was not found in the fixture file.';
            }

            if ($resolverDelegate instanceof ComponentDefinitionProviderInterface) {
                try {
                    $transformers = $this->transformersFactory->get($resolverDelegate, $componentName);
                } catch (Throwable $throwable) {
                    $errors[] = 'Component transformers could not be loaded: ' . $throwable->getMessage();
                }
            }

            try {
                $slotValues = $fixture->selectedVariant !== null
                    ? $this->componentFixtureProvider->getVariantSlots($resolverDelegate, $componentName, $variantName, $slots)
                    : [];
            } catch (Throwable $throwable) {
                $slotValues = [];
                $errors[] = 'Component slots could not be loaded: ' . $throwable->getMessage();
            }
            $fixture = $this->mergeFixtureValuesWithArguments($fixture, $arguments, $transformers, $slotValues);
            try {
                $staticVariables = $this->normalizeStaticVariables($resolverDelegate->getAdditionalVariables($componentName));
            } catch (Throwable $throwable) {
                $errors[] = 'Static variables could not be loaded: ' . $throwable->getMessage();
            }
        } else {
            $errors[] = 'The component collection does not provide template metadata.';
        }

        return new ComponentMetadata(
            $variantIdentifier,
            $variantName,
            $this->getVariantLabel($variantName),
            $namespace . ':' . $componentName,
            $componentName,
            $namespace,
            $component['sourceNamespace'],
            $namespace !== $component['sourceNamespace'],
            $component['collectionClass'],
            $arguments,
            $additionalArgumentsAllowed,
            $slots,
            $annotations,
            $template,
            $fixture,
            $staticVariables,
            $errors,
        );
    }

    /**
     * @param list<ComponentVariantValueMetadata> $variantValues
     * @param list<ComponentArgumentMetadata> $arguments
     * @return list<ComponentVariantValueMetadata>
     */
    private function mergeVariantValuesWithArguments(array $variantValues, array $arguments, ?Transformers $transformers): array
    {
        $variantValuesByName = [];
        foreach ($variantValues as $variantValue) {
            $variantValuesByName[$variantValue->name] = $variantValue;
        }

        $mergedValues = [];
        foreach ($arguments as $argument) {
            $variantValue = $variantValuesByName[$argument->name] ?? null;
            $transformer = $transformers?->arguments[$argument->name] ?? null;
            if ($transformer !== null) {
                $inputValues = is_array($variantValue?->nativeValue) ? $variantValue->nativeValue : [];
                foreach ($transformer->arguments as $inputName => $inputDefinition) {
                    $hasValue = array_key_exists($inputName, $inputValues);
                    $value = $hasValue ? $inputValues[$inputName] : $inputDefinition->getDefaultValue();
                    $mergedValues[] = new ComponentVariantValueMetadata(
                        $inputName,
                        $inputDefinition->getType(),
                        $inputDefinition->getDescription(),
                        $this->formatTransformerInputValue($inputDefinition->getType(), $value),
                        $value,
                        is_string($value) && str_contains($value, "\n"),
                        $hasValue,
                        $inputDefinition->isRequired(),
                        $argument->name,
                        $argument->name . '.' . $inputName,
                        $this->isDateType($inputDefinition->getType()),
                        $this->getEnumOptions($inputDefinition->getType()),
                        $transformer->from,
                    );
                }

                continue;
            }

            if ($variantValue === null) {
                $mergedValues[] = new ComponentVariantValueMetadata(
                    $argument->name,
                    $argument->type,
                    $argument->description,
                    $argument->defaultValue,
                    $argument->defaultValue,
                    str_contains($argument->defaultValue, "\n"),
                    false,
                    $argument->required,
                    null,
                    $argument->name,
                );
                continue;
            }

            $mergedValues[] = new ComponentVariantValueMetadata(
                $argument->name,
                $argument->type,
                $argument->description,
                $variantValue->value,
                $variantValue->nativeValue,
                $variantValue->isMultiline,
                $variantValue->isFixtureValue,
                $argument->required,
                null,
                $argument->name,
            );
        }

        return $mergedValues;
    }

    /**
     * @param list<ComponentArgumentMetadata> $arguments
     * @param array<string, string> $slots
     */
    private function mergeFixtureValuesWithArguments(ComponentFixtureMetadata $fixture, array $arguments, ?Transformers $transformers, array $slots = []): ComponentFixtureMetadata
    {
        if ($fixture->selectedVariant === null) {
            return $fixture;
        }

        return new ComponentFixtureMetadata(
            $fixture->absolutePath,
            $fixture->extensionPath,
            $fixture->content,
            $fixture->variants,
            $fixture->error,
            new ComponentVariantMetadata(
                $fixture->selectedVariant->name,
                $this->mergeVariantValuesWithArguments($fixture->selectedVariant->values, $arguments, $transformers),
                $slots,
            ),
        );
    }

    /**
     * @return array{sourceNamespace: string, collectionClass: class-string, resolverDelegate: object}|null
     */
    private function resolveComponent(string $namespace, string $componentName): ?array
    {
        $fluidNamespaceAliases = $this->getFluidNamespaceAliasesByClassNamespace();
        $resolverDelegates = $this->componentResolverDelegateProvider->getAll();
        ksort($resolverDelegates);

        foreach ($resolverDelegates as $classNamespace => $resolverDelegate) {
            $displayNamespace = $fluidNamespaceAliases[$classNamespace] ?? $classNamespace;
            if ($displayNamespace !== $namespace || !$this->componentDiscoveryProvider->hasComponent($resolverDelegate, $componentName)) {
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
     * @return list<ComponentArgumentMetadata>
     */
    private function normalizeArgumentDefinitions(array $argumentDefinitions): array
    {
        $arguments = [];
        foreach ($argumentDefinitions as $argumentDefinition) {
            $arguments[] = new ComponentArgumentMetadata(
                $argumentDefinition->getName(),
                $argumentDefinition->getType(),
                $argumentDefinition->getDescription(),
                $argumentDefinition->isRequired(),
                ValueFormatter::format($argumentDefinition->getDefaultValue()),
                match ($argumentDefinition->getEscape()) {
                    true => 'enabled',
                    false => 'disabled',
                    null => 'default',
                },
                array_values(array_map(static fn(object $annotation): string => $annotation::class, $argumentDefinition->getAnnotations())),
            );
        }

        return $arguments;
    }

    private function getTemplateMetadata(ComponentTemplateResolverInterface $resolverDelegate, string $componentName): ComponentTemplateMetadata
    {
        $templateName = $resolverDelegate->resolveTemplateName($componentName);
        $templatePaths = $resolverDelegate->getTemplatePaths();
        $absolutePath = null;
        $content = null;
        $error = null;

        try {
            $absolutePath = $templatePaths->resolveTemplateFileForControllerAndActionAndFormat('Default', $templateName, null, true);
            $content = $absolutePath !== null ? file_get_contents($absolutePath) : null;
            if ($content === false) {
                $content = null;
                $error = 'Template file could not be read.';
            }
        } catch (Throwable $throwable) {
            $error = $throwable->getMessage();
        }

        return new ComponentTemplateMetadata(
            $templateName,
            $absolutePath,
            is_string($absolutePath) ? $this->getExtensionPath($absolutePath) : null,
            is_string($absolutePath) ? $this->getRelativePath($absolutePath) : null,
            $this->normalizeTemplateRootPaths($templatePaths->getTemplateRootPaths()),
            $content,
            $error,
        );
    }

    private function getFixtureMetadata(ComponentTemplateResolverInterface $resolverDelegate, string $componentName, string $variantName): ComponentFixtureMetadata
    {
        $fixture = $this->componentFixtureProvider->getFixtureMetadata($resolverDelegate, $componentName);
        $selectedVariant = array_find($fixture->variants, fn($variant): bool => $variant->name === $variantName);

        return new ComponentFixtureMetadata(
            $fixture->absolutePath,
            $fixture->extensionPath,
            $fixture->content,
            $fixture->variants,
            $fixture->error,
            $selectedVariant,
        );
    }

    /**
     * @param array<string|int, string> $templateRootPaths
     * @return list<ComponentTemplateRootPathMetadata>
     */
    private function normalizeTemplateRootPaths(array $templateRootPaths): array
    {
        $rootPaths = [];
        foreach ($templateRootPaths as $priority => $rootPath) {
            $rootPaths[] = new ComponentTemplateRootPathMetadata($priority, $rootPath, $this->getExtensionPath($rootPath));
        }

        return $rootPaths;
    }

    /**
     * @param array<string, mixed> $staticVariables
     * @return list<ComponentStaticVariableMetadata>
     */
    private function normalizeStaticVariables(array $staticVariables): array
    {
        $variables = [];
        foreach ($staticVariables as $name => $value) {
            $variables[] = new ComponentStaticVariableMetadata((string)$name, get_debug_type($value), ValueFormatter::format($value));
        }

        return $variables;
    }

    private function formatTransformerInputValue(string $type, mixed $value): string
    {
        if (!$this->isDateType($type) || !is_string($value)) {
            return ValueFormatter::format($value);
        }

        try {
            return new DateTimeImmutable($value)->format('Y-m-d\\TH:i');
        } catch (Throwable) {
            return $value;
        }
    }

    private function isDateType(string $type): bool
    {
        return in_array($type, [DateTime::class, DateTimeImmutable::class, DateTimeInterface::class], true);
    }

    /**
     * @return array<string, string>
     */
    private function getEnumOptions(string $type): array
    {
        if (!enum_exists($type)) {
            return [];
        }

        $options = [];
        foreach ($type::cases() as $case) {
            $options[$case->name] = $case->name;
        }

        return $options;
    }

    private function getExtensionPath(string $absolutePath): ?string
    {
        $absolutePath = rtrim($absolutePath, '/');
        foreach ($this->packageManager->getActivePackages() as $package) {
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
                if (!is_string($classNamespace)) {
                    continue;
                }

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
