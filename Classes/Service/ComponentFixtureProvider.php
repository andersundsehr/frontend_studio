<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use Symfony\Component\Yaml\Yaml;
use Throwable;
use TYPO3\CMS\Core\Package\PackageInterface;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3Fluid\Fluid\Core\Component\ComponentTemplateResolverInterface;

final readonly class ComponentFixtureProvider
{
    public function __construct(
        private PackageManager $packageManager,
    ) {}

    /**
     * @return array{
     *     absolutePath: string|null,
     *     extensionPath: string|null,
     *     content: string|null,
     *     variants: list<array{name: string, values: list<array{name: string, type: string, value: string}>}>,
     *     error: string|null
     * }
     */
    public function getFixtureMetadata(ComponentTemplateResolverInterface $resolverDelegate, string $componentName): array
    {
        $absolutePath = null;
        $content = null;
        $variants = [];
        $error = null;

        try {
            $templatePath = $this->resolveTemplatePath($resolverDelegate, $componentName);
            if ($templatePath === null) {
                throw new \RuntimeException('Component template path could not be resolved.');
            }

            $absolutePath = $this->getFixturePath($templatePath);
            if ($absolutePath === null) {
                throw new \RuntimeException('Fixture path could not be derived from template path "' . $templatePath . '".');
            }

            if (!is_file($absolutePath)) {
                return [
                    'absolutePath' => $absolutePath,
                    'extensionPath' => $this->getExtensionPath($absolutePath),
                    'content' => null,
                    'variants' => [],
                    'error' => null,
                ];
            }

            $content = file_get_contents($absolutePath);
            if ($content === false) {
                throw new \RuntimeException('Fixture file could not be read.');
            }

            $fixture = Yaml::parseFile($absolutePath);
            if (!is_array($fixture) || !isset($fixture['variants']) || !is_array($fixture['variants'])) {
                throw new \RuntimeException('Fixture file must contain a top-level "variants" map.');
            }

            foreach ($fixture['variants'] as $variantName => $variantValues) {
                $variants[] = [
                    'name' => (string)$variantName,
                    'values' => $this->normalizeVariantValues(is_array($variantValues) ? $variantValues : []),
                ];
            }
        } catch (Throwable $throwable) {
            $error = $throwable->getMessage();
        }

        return [
            'absolutePath' => $absolutePath,
            'extensionPath' => is_string($absolutePath) ? $this->getExtensionPath($absolutePath) : null,
            'content' => $content,
            'variants' => $variants,
            'error' => $error,
        ];
    }

    /**
     * @return array{fixturePath: string, fixtureExtensionPath: string|null}
     */
    public function renameVariant(
        ComponentTemplateResolverInterface $resolverDelegate,
        string $componentName,
        string $currentVariantName,
        string $newVariantName,
    ): array {
        $newVariantName = trim($newVariantName);
        if ($newVariantName === '') {
            throw new \InvalidArgumentException('The variant title must not be empty.');
        }

        if ($newVariantName === $currentVariantName) {
            $fixturePath = $this->resolveFixturePath($resolverDelegate, $componentName);
            return [
                'fixturePath' => $fixturePath,
                'fixtureExtensionPath' => $this->getExtensionPath($fixturePath),
            ];
        }

        $fixturePath = $this->resolveFixturePath($resolverDelegate, $componentName);
        if (!is_file($fixturePath)) {
            throw new \RuntimeException('Fixture file does not exist.');
        }

        $fixture = Yaml::parseFile($fixturePath);
        if (!is_array($fixture) || !isset($fixture['variants']) || !is_array($fixture['variants'])) {
            throw new \RuntimeException('Fixture file must contain a top-level "variants" map.');
        }

        if (!array_key_exists($currentVariantName, $fixture['variants'])) {
            throw new \RuntimeException('The variant "' . $currentVariantName . '" does not exist.');
        }

        if (array_key_exists($newVariantName, $fixture['variants'])) {
            throw new \RuntimeException('The variant "' . $newVariantName . '" already exists.');
        }

        $renamedVariants = [];
        foreach ($fixture['variants'] as $variantName => $variantValues) {
            $renamedVariants[$variantName === $currentVariantName ? $newVariantName : $variantName] = $variantValues;
        }
        $fixture['variants'] = $renamedVariants;

        $bytesWritten = file_put_contents($fixturePath, Yaml::dump($fixture, 99, 2));
        if ($bytesWritten === false) {
            throw new \RuntimeException('Fixture file could not be written.');
        }

        return [
            'fixturePath' => $fixturePath,
            'fixtureExtensionPath' => $this->getExtensionPath($fixturePath),
        ];
    }

    /**
     * @param array<string, mixed> $variantValues
     * @return array{fixturePath: string, fixtureExtensionPath: string|null}
     */
    public function createVariant(
        ComponentTemplateResolverInterface $resolverDelegate,
        string $componentName,
        string $variantName,
        array $variantValues = [],
    ): array {
        $variantName = trim($variantName);
        if ($variantName === '') {
            throw new \InvalidArgumentException('The variant title must not be empty.');
        }

        $fixturePath = $this->resolveFixturePath($resolverDelegate, $componentName);
        if (is_file($fixturePath)) {
            $fixture = Yaml::parseFile($fixturePath);
            if (!is_array($fixture) || !isset($fixture['variants']) || !is_array($fixture['variants'])) {
                throw new \RuntimeException('Fixture file must contain a top-level "variants" map.');
            }
        } else {
            $fixture = ['variants' => []];
        }

        if (array_key_exists($variantName, $fixture['variants'])) {
            throw new \RuntimeException('The variant "' . $variantName . '" already exists.');
        }

        $fixture['variants'][$variantName] = $variantValues;

        $bytesWritten = file_put_contents($fixturePath, Yaml::dump($fixture, 99, 2));
        if ($bytesWritten === false) {
            throw new \RuntimeException('Fixture file could not be written.');
        }

        return [
            'fixturePath' => $fixturePath,
            'fixtureExtensionPath' => $this->getExtensionPath($fixturePath),
        ];
    }

    /**
     * @return array{fixturePath: string, fixtureExtensionPath: string|null}
     */
    public function deleteVariant(
        ComponentTemplateResolverInterface $resolverDelegate,
        string $componentName,
        string $variantName,
    ): array {
        $fixturePath = $this->resolveFixturePath($resolverDelegate, $componentName);
        if (!is_file($fixturePath)) {
            throw new \RuntimeException('Fixture file does not exist.');
        }

        $fixture = Yaml::parseFile($fixturePath);
        if (!is_array($fixture) || !isset($fixture['variants']) || !is_array($fixture['variants'])) {
            throw new \RuntimeException('Fixture file must contain a top-level "variants" map.');
        }

        if (!array_key_exists($variantName, $fixture['variants'])) {
            throw new \RuntimeException('The variant "' . $variantName . '" does not exist.');
        }

        unset($fixture['variants'][$variantName]);

        $bytesWritten = file_put_contents($fixturePath, Yaml::dump($fixture, 99, 2));
        if ($bytesWritten === false) {
            throw new \RuntimeException('Fixture file could not be written.');
        }

        return [
            'fixturePath' => $fixturePath,
            'fixtureExtensionPath' => $this->getExtensionPath($fixturePath),
        ];
    }

    /**
     * @param array<string, mixed> $variantValues
     * @return array{fixturePath: string, fixtureExtensionPath: string|null, values: array<string, mixed>}
     */
    public function updateVariantValues(
        ComponentTemplateResolverInterface $resolverDelegate,
        string $componentName,
        string $variantName,
        array $variantValues,
    ): array {
        $fixturePath = $this->resolveFixturePath($resolverDelegate, $componentName);
        if (!is_file($fixturePath)) {
            throw new \RuntimeException('Fixture file does not exist.');
        }

        $fixture = Yaml::parseFile($fixturePath);
        if (!is_array($fixture) || !isset($fixture['variants']) || !is_array($fixture['variants'])) {
            throw new \RuntimeException('Fixture file must contain a top-level "variants" map.');
        }

        if (!array_key_exists($variantName, $fixture['variants'])) {
            throw new \RuntimeException('The variant "' . $variantName . '" does not exist.');
        }

        $fixture['variants'][$variantName] = $this->normalizeSubmittedVariantValues($variantValues);

        $bytesWritten = file_put_contents($fixturePath, Yaml::dump($fixture, 99, 2));
        if ($bytesWritten === false) {
            throw new \RuntimeException('Fixture file could not be written.');
        }

        return [
            'fixturePath' => $fixturePath,
            'fixtureExtensionPath' => $this->getExtensionPath($fixturePath),
            'values' => $fixture['variants'][$variantName],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getVariantValues(
        ComponentTemplateResolverInterface $resolverDelegate,
        string $componentName,
        string $variantName,
    ): array {
        $fixturePath = $this->resolveFixturePath($resolverDelegate, $componentName);
        if (!is_file($fixturePath)) {
            throw new \RuntimeException('Fixture file does not exist.');
        }

        $fixture = Yaml::parseFile($fixturePath);
        if (!is_array($fixture) || !isset($fixture['variants']) || !is_array($fixture['variants'])) {
            throw new \RuntimeException('Fixture file must contain a top-level "variants" map.');
        }

        if (!array_key_exists($variantName, $fixture['variants'])) {
            throw new \RuntimeException('The variant "' . $variantName . '" does not exist.');
        }

        $variantValues = $fixture['variants'][$variantName];
        if (!is_array($variantValues)) {
            return [];
        }

        return $variantValues;
    }

    private function resolveTemplatePath(ComponentTemplateResolverInterface $resolverDelegate, string $componentName): ?string
    {
        $templateName = $resolverDelegate->resolveTemplateName($componentName);

        return $resolverDelegate->getTemplatePaths()->resolveTemplateFileForControllerAndActionAndFormat(
            'Default',
            $templateName,
            null,
            true,
        );
    }

    private function resolveFixturePath(ComponentTemplateResolverInterface $resolverDelegate, string $componentName): string
    {
        $templatePath = $this->resolveTemplatePath($resolverDelegate, $componentName);
        if ($templatePath === null) {
            throw new \RuntimeException('Component template path could not be resolved.');
        }

        $fixturePath = $this->getFixturePath($templatePath);
        if ($fixturePath === null) {
            throw new \RuntimeException('Fixture path could not be derived from template path "' . $templatePath . '".');
        }

        return $fixturePath;
    }

    private function getFixturePath(string $templatePath): ?string
    {
        if (str_ends_with($templatePath, '.fluid.html')) {
            return substr($templatePath, 0, -strlen('.fluid.html')) . '.fixture.yaml';
        }

        if (str_ends_with($templatePath, '.html')) {
            return substr($templatePath, 0, -strlen('.html')) . '.fixture.yaml';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $variantValues
     * @return list<array{name: string, type: string, value: string, isMultiline: bool, isFixtureValue: bool}>
     */
    private function normalizeVariantValues(array $variantValues): array
    {
        $values = [];
        foreach ($variantValues as $name => $value) {
            $values[] = [
                'name' => (string)$name,
                'type' => get_debug_type($value),
                'value' => $this->normalizeValue($value),
                'isMultiline' => is_string($value) && str_contains($value, "\n"),
                'isFixtureValue' => true,
            ];
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $variantValues
     * @return array<string, mixed>
     */
    private function normalizeSubmittedVariantValues(array $variantValues): array
    {
        $normalizedValues = [];
        foreach ($variantValues as $name => $value) {
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }

            $normalizedValues[$name] = $value;
        }

        return $normalizedValues;
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

    public function getExtensionPath(string $absolutePath): ?string
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
}
