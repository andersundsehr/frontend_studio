<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use Andersundsehr\FrontendStudio\Dto\ComponentVariantValues;
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
     *     variants: list<array{name: string, values: list<array{name: string, type: string, value: string, nativeValue: mixed, isMultiline: bool, isFixtureValue: bool}>}>,
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
                    'values' => ComponentVariantValues::fromYamlValues($variantValues)->toMetadataList(),
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
    public function copyVariant(
        ComponentTemplateResolverInterface $resolverDelegate,
        string $componentName,
        string $sourceVariantName,
        string $newVariantName,
        ?ComponentVariantValues $variantValues = null,
    ): array {
        $newVariantName = trim($newVariantName);
        if ($newVariantName === '') {
            throw new \InvalidArgumentException('The variant title must not be empty.');
        }

        $fixturePath = $this->resolveFixturePath($resolverDelegate, $componentName);
        if (!is_file($fixturePath)) {
            throw new \RuntimeException('Fixture file does not exist.');
        }

        $fixture = Yaml::parseFile($fixturePath);
        if (!is_array($fixture) || !isset($fixture['variants']) || !is_array($fixture['variants'])) {
            throw new \RuntimeException('Fixture file must contain a top-level "variants" map.');
        }

        if (!array_key_exists($sourceVariantName, $fixture['variants'])) {
            throw new \RuntimeException('The variant "' . $sourceVariantName . '" does not exist.');
        }

        if (array_key_exists($newVariantName, $fixture['variants'])) {
            throw new \RuntimeException('The variant "' . $newVariantName . '" already exists.');
        }

        $sourceVariantValues = $fixture['variants'][$sourceVariantName];
        $fixture['variants'][$newVariantName] = $variantValues !== null
            ? $variantValues->toYamlArray()
            : ComponentVariantValues::fromYamlValues($sourceVariantValues)->toYamlArray();

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
     * @return array{fixturePath: string, fixtureExtensionPath: string|null, values: array<string, mixed>}
     */
    public function updateVariantValues(
        ComponentTemplateResolverInterface $resolverDelegate,
        string $componentName,
        string $variantName,
        ComponentVariantValues $variantValues,
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

        $fixture['variants'][$variantName] = $variantValues->toYamlArray();

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

    public function getVariantValues(
        ComponentTemplateResolverInterface $resolverDelegate,
        string $componentName,
        string $variantName,
    ): ComponentVariantValues {
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
        return ComponentVariantValues::fromYamlValues($variantValues);
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
