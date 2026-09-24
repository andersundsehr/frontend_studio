<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use RuntimeException;
use InvalidArgumentException;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantValues;
use Andersundsehr\FrontendStudio\Dto\ComponentFixtureMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantMetadata;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3Fluid\Fluid\Core\Component\ComponentTemplateResolverInterface;

final readonly class ComponentFixtureProvider
{
    public function __construct(
        private PackageManager $packageManager,
    ) {
    }

    public function getFixtureMetadata(ComponentTemplateResolverInterface $resolverDelegate, string $componentName): ComponentFixtureMetadata
    {
        $absolutePath = null;
        $content = null;
        $variants = [];
        $error = null;

        try {
            $templatePath = $this->resolveTemplatePath($resolverDelegate, $componentName);
            if ($templatePath === null) {
                throw new RuntimeException('Component template path could not be resolved.', 1098271731);
            }

            $absolutePath = $this->getFixturePath($templatePath);
            if ($absolutePath === null) {
                throw new RuntimeException('Fixture path could not be derived from template path "' . $templatePath . '".', 1919059575);
            }

            if (!is_file($absolutePath)) {
                return new ComponentFixtureMetadata($absolutePath, $this->getExtensionPath($absolutePath), null, [], null);
            }

            $fixtureContent = file_get_contents($absolutePath);
            if (!is_string($fixtureContent)) {
                throw new RuntimeException('Fixture file could not be read.', 2483720305);
            }

            $content = $fixtureContent;

            $fixture = Yaml::parseFile($absolutePath, Yaml::PARSE_CONSTANT);
            if (!is_array($fixture) || !isset($fixture['variants']) || !is_array($fixture['variants'])) {
                throw new RuntimeException('Fixture file must contain a top-level "variants" map.', 9491866460);
            }

            foreach ($fixture['variants'] as $variantName => $variantValues) {
                $variants[] = new ComponentVariantMetadata(
                    (string)$variantName,
                    ComponentVariantValues::fromYamlValues($variantValues)->toMetadataList(),
                );
            }
        } catch (Throwable $throwable) {
            $error = $throwable->getMessage();
        }

        return new ComponentFixtureMetadata(
            $absolutePath,
            is_string($absolutePath) ? $this->getExtensionPath($absolutePath) : null,
            $content,
            $variants,
            $error,
        );
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
            throw new InvalidArgumentException('The variant title must not be empty.', 6596881576);
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
            throw new RuntimeException('Fixture file does not exist.', 3271471519);
        }

        $fixture = Yaml::parseFile($fixturePath, Yaml::PARSE_CONSTANT);
        if (!is_array($fixture) || !isset($fixture['variants']) || !is_array($fixture['variants'])) {
            throw new RuntimeException('Fixture file must contain a top-level "variants" map.', 1298473157);
        }

        if (!array_key_exists($currentVariantName, $fixture['variants'])) {
            throw new RuntimeException('The variant "' . $currentVariantName . '" does not exist.', 6191154598);
        }

        if (array_key_exists($newVariantName, $fixture['variants'])) {
            throw new RuntimeException('The variant "' . $newVariantName . '" already exists.', 6822966409);
        }

        $renamedVariants = [];
        foreach ($fixture['variants'] as $variantName => $variantValues) {
            $renamedVariants[$variantName === $currentVariantName ? $newVariantName : $variantName] = $variantValues;
        }

        $fixture['variants'] = $renamedVariants;

        $bytesWritten = file_put_contents($fixturePath, Yaml::dump($fixture, 99, 2));
        if ($bytesWritten === false) {
            throw new RuntimeException('Fixture file could not be written.', 6265288681);
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
            throw new InvalidArgumentException('The variant title must not be empty.', 8617766585);
        }

        $fixturePath = $this->resolveFixturePath($resolverDelegate, $componentName);
        if (is_file($fixturePath)) {
            $fixture = Yaml::parseFile($fixturePath, Yaml::PARSE_CONSTANT);
            if (!is_array($fixture) || !isset($fixture['variants']) || !is_array($fixture['variants'])) {
                throw new RuntimeException('Fixture file must contain a top-level "variants" map.', 5101321905);
            }
        } else {
            $fixture = ['variants' => []];
        }

        if (array_key_exists($variantName, $fixture['variants'])) {
            throw new RuntimeException('The variant "' . $variantName . '" already exists.', 5796181384);
        }

        $fixture['variants'][$variantName] = $variantValues;

        $bytesWritten = file_put_contents($fixturePath, Yaml::dump($fixture, 99, 2));
        if ($bytesWritten === false) {
            throw new RuntimeException('Fixture file could not be written.', 8388962290);
        }

        return [
            'fixturePath' => $fixturePath,
            'fixtureExtensionPath' => $this->getExtensionPath($fixturePath),
        ];
    }

    /**
     * @param array<string, string> $argumentTypes
     * @return array{fixturePath: string, fixtureExtensionPath: string|null}
     */
    public function copyVariant(
        ComponentTemplateResolverInterface $resolverDelegate,
        string $componentName,
        string $sourceVariantName,
        string $newVariantName,
        ?ComponentVariantValues $variantValues = null,
        array $argumentTypes = [],
    ): array {
        $newVariantName = trim($newVariantName);
        if ($newVariantName === '') {
            throw new InvalidArgumentException('The variant title must not be empty.', 6666536237);
        }

        $fixturePath = $this->resolveFixturePath($resolverDelegate, $componentName);
        if (!is_file($fixturePath)) {
            throw new RuntimeException('Fixture file does not exist.', 4783618831);
        }

        $fixture = Yaml::parseFile($fixturePath, Yaml::PARSE_CONSTANT);
        if (!is_array($fixture) || !isset($fixture['variants']) || !is_array($fixture['variants'])) {
            throw new RuntimeException('Fixture file must contain a top-level "variants" map.', 6187566018);
        }

        if (!array_key_exists($sourceVariantName, $fixture['variants'])) {
            throw new RuntimeException('The variant "' . $sourceVariantName . '" does not exist.', 3816407312);
        }

        if (array_key_exists($newVariantName, $fixture['variants'])) {
            throw new RuntimeException('The variant "' . $newVariantName . '" already exists.', 4042281802);
        }

        $sourceVariantValues = $fixture['variants'][$sourceVariantName];
        $fixture['variants'][$newVariantName] = $variantValues !== null
            ? $variantValues->toYamlArray()
            : ComponentVariantValues::fromYamlValues($sourceVariantValues)->normalizeForArgumentTypes($argumentTypes)->toYamlArray();

        $bytesWritten = file_put_contents($fixturePath, Yaml::dump($fixture, 99, 2));
        if ($bytesWritten === false) {
            throw new RuntimeException('Fixture file could not be written.', 6538942559);
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
            throw new RuntimeException('Fixture file does not exist.', 8076247836);
        }

        $fixture = Yaml::parseFile($fixturePath, Yaml::PARSE_CONSTANT);
        if (!is_array($fixture) || !isset($fixture['variants']) || !is_array($fixture['variants'])) {
            throw new RuntimeException('Fixture file must contain a top-level "variants" map.', 4255598607);
        }

        if (!array_key_exists($variantName, $fixture['variants'])) {
            throw new RuntimeException('The variant "' . $variantName . '" does not exist.', 4356859594);
        }

        unset($fixture['variants'][$variantName]);

        $bytesWritten = file_put_contents($fixturePath, Yaml::dump($fixture, 99, 2));
        if ($bytesWritten === false) {
            throw new RuntimeException('Fixture file could not be written.', 8814357264);
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
            throw new RuntimeException('Fixture file does not exist.', 3973298810);
        }

        $fixture = Yaml::parseFile($fixturePath, Yaml::PARSE_CONSTANT);
        if (!is_array($fixture) || !isset($fixture['variants']) || !is_array($fixture['variants'])) {
            throw new RuntimeException('Fixture file must contain a top-level "variants" map.', 7009596763);
        }

        if (!array_key_exists($variantName, $fixture['variants'])) {
            throw new RuntimeException('The variant "' . $variantName . '" does not exist.', 9161589254);
        }

        $fixture['variants'][$variantName] = $variantValues->toYamlArray();

        $bytesWritten = file_put_contents($fixturePath, Yaml::dump($fixture, 99, 2));
        if ($bytesWritten === false) {
            throw new RuntimeException('Fixture file could not be written.', 6351024941);
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
            throw new RuntimeException('Fixture file does not exist.', 7881463807);
        }

        $fixture = Yaml::parseFile($fixturePath, Yaml::PARSE_CONSTANT);
        if (!is_array($fixture) || !isset($fixture['variants']) || !is_array($fixture['variants'])) {
            throw new RuntimeException('Fixture file must contain a top-level "variants" map.', 4719164179);
        }

        if (!array_key_exists($variantName, $fixture['variants'])) {
            throw new RuntimeException('The variant "' . $variantName . '" does not exist.', 4628371251);
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
            throw new RuntimeException('Component template path could not be resolved.', 6040508781);
        }

        $fixturePath = $this->getFixturePath($templatePath);
        if ($fixturePath === null) {
            throw new RuntimeException('Fixture path could not be derived from template path "' . $templatePath . '".', 7411493034);
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
