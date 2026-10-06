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
        private ComponentWritePolicy $writePolicy = new ComponentWritePolicy(),
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

            $this->parsePreviewWrapper($fixture);
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
     * @param list<string> $slotNames
     * @return array{fixturePath: string, fixtureExtensionPath: string|null}
     */
    public function renameVariant(
        ComponentTemplateResolverInterface $resolverDelegate,
        string $componentName,
        string $currentVariantName,
        string $newVariantName,
        array $slotNames = [],
    ): array {
        $this->writePolicy->assertWritable();
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

        $this->assertSlotNamesAreUnique($slotNames);

        $renamedVariants = [];
        foreach ($fixture['variants'] as $variantName => $variantValues) {
            $renamedVariants[$variantName === $currentVariantName ? $newVariantName : $variantName] = $variantValues;
        }

        $fixture['variants'] = $renamedVariants;
        $this->assertVariantNamesAreUnique($fixture['variants'], $slotNames);
        $this->renameSlotFiles($resolverDelegate, $componentName, $currentVariantName, $newVariantName);

        $bytesWritten = file_put_contents($fixturePath, $this->dumpFixture($fixture, $slotNames));
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
     * @param list<string> $slotNames
     * @return array{fixturePath: string, fixtureExtensionPath: string|null}
     */
    public function createVariant(
        ComponentTemplateResolverInterface $resolverDelegate,
        string $componentName,
        string $variantName,
        array $variantValues = [],
        array $slotNames = [],
    ): array {
        $this->writePolicy->assertWritable();
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
        $this->assertSlotNamesAreUnique($slotNames);
        $this->assertVariantNamesAreUnique($fixture['variants'], $slotNames);

        $bytesWritten = file_put_contents($fixturePath, $this->dumpFixture($fixture, $slotNames));
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
     * @param array<string, mixed>|null $slotValues
     * @param list<string> $slotNames
     * @return array{fixturePath: string, fixtureExtensionPath: string|null}
     */
    public function copyVariant(
        ComponentTemplateResolverInterface $resolverDelegate,
        string $componentName,
        string $sourceVariantName,
        string $newVariantName,
        ?ComponentVariantValues $variantValues = null,
        array $argumentTypes = [],
        ?array $slotValues = null,
        array $slotNames = [],
    ): array {
        $this->writePolicy->assertWritable();
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
        $fixture['variants'][$newVariantName] = $variantValues?->toYamlArray() ?? ComponentVariantValues::fromYamlValues($sourceVariantValues)->normalizeForArgumentTypes($argumentTypes)->toYamlArray();
        $this->assertSlotNamesAreUnique($slotNames);
        $this->assertVariantNamesAreUnique($fixture['variants'], $slotNames);
        $this->copySlotFiles($resolverDelegate, $componentName, $sourceVariantName, $newVariantName, $slotValues, $slotNames);

        $bytesWritten = file_put_contents($fixturePath, $this->dumpFixture($fixture, $slotNames));
        if ($bytesWritten === false) {
            throw new RuntimeException('Fixture file could not be written.', 6538942559);
        }

        return [
            'fixturePath' => $fixturePath,
            'fixtureExtensionPath' => $this->getExtensionPath($fixturePath),
        ];
    }

    /**
     * @param list<string> $slotNames
     * @return array{fixturePath: string, fixtureExtensionPath: string|null}
     */
    public function deleteVariant(
        ComponentTemplateResolverInterface $resolverDelegate,
        string $componentName,
        string $variantName,
        array $slotNames = [],
    ): array {
        $this->writePolicy->assertWritable();
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

        $this->assertSlotNamesAreUnique($slotNames);
        $this->assertVariantNamesAreUnique($fixture['variants'], $slotNames);

        unset($fixture['variants'][$variantName]);
        $this->deleteSlotFiles($resolverDelegate, $componentName, $variantName);

        $bytesWritten = file_put_contents($fixturePath, $this->dumpFixture($fixture, $slotNames));
        if ($bytesWritten === false) {
            throw new RuntimeException('Fixture file could not be written.', 8814357264);
        }

        return [
            'fixturePath' => $fixturePath,
            'fixtureExtensionPath' => $this->getExtensionPath($fixturePath),
        ];
    }

    /**
     * @param array<string, mixed>|null $slotValues
     * @param list<string> $slotNames
     * @return array{fixturePath: string, fixtureExtensionPath: string|null, values: array<string, mixed>}
     */
    public function updateVariantValues(
        ComponentTemplateResolverInterface $resolverDelegate,
        string $componentName,
        string $variantName,
        ComponentVariantValues $variantValues,
        ?array $slotValues = null,
        array $slotNames = [],
    ): array {
        $this->writePolicy->assertWritable();
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

        $this->assertSlotNamesAreUnique($slotNames);
        $this->assertVariantNamesAreUnique($fixture['variants'], $slotNames);

        $fixture['variants'][$variantName] = $variantValues->toYamlArray();
        if ($slotValues !== null) {
            $this->writeSlotFiles($resolverDelegate, $componentName, $variantName, $slotValues, $slotNames);
        }

        $bytesWritten = file_put_contents($fixturePath, $this->dumpFixture($fixture, $slotNames));
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

    public function getPreviewWrapper(ComponentTemplateResolverInterface $resolverDelegate, string $componentName): ?string
    {
        $fixturePath = $this->resolveFixturePath($resolverDelegate, $componentName);
        if (!is_file($fixturePath)) {
            return null;
        }

        $fixture = Yaml::parseFile($fixturePath, Yaml::PARSE_CONSTANT);
        if (!is_array($fixture)) {
            throw new InvalidArgumentException('Fixture file must contain a top-level map.', 1791192000);
        }

        return $this->parsePreviewWrapper($fixture);
    }

    /** @param array<mixed> $fixture */
    private function parsePreviewWrapper(array $fixture): ?string
    {
        if (!array_key_exists('wrapper', $fixture)) {
            return null;
        }

        $wrapper = $fixture['wrapper'];
        if (!is_string($wrapper) || trim($wrapper) === '' || substr_count($wrapper, '{{component}}') !== 1) {
            throw new InvalidArgumentException('Fixture "wrapper" must be a non-empty string containing exactly one {{component}} placeholder.', 1791192001);
        }

        return $wrapper;
    }

    /**
     * @return list<string>
     */
    public function getPreviewStylesheets(ComponentTemplateResolverInterface $resolverDelegate, string $componentName): array
    {
        $fixturePath = $this->resolveFixturePath($resolverDelegate, $componentName);
        if (!is_file($fixturePath)) {
            return [];
        }

        $fixture = Yaml::parseFile($fixturePath, Yaml::PARSE_CONSTANT);
        $stylesheets = $fixture['stylesheets'] ?? [];
        if (!is_array($stylesheets) || !array_is_list($stylesheets)) {
            throw new InvalidArgumentException('Fixture "stylesheets" must be a list of paths.', 3430725123);
        }

        foreach ($stylesheets as $stylesheet) {
            if (!is_string($stylesheet) || trim($stylesheet) === '') {
                throw new InvalidArgumentException('Fixture "stylesheets" must contain non-empty paths.', 3430725124);
            }
        }

        return $stylesheets;
    }

    /**
     * @param list<string> $slotNames
     * @return array<string, string>
     */
    public function getVariantSlots(
        ComponentTemplateResolverInterface $resolverDelegate,
        string $componentName,
        string $variantName,
        array $slotNames,
    ): array {
        $this->assertSlotNamesAreUnique($slotNames);
        $fixturePath = $this->resolveFixturePath($resolverDelegate, $componentName);
        if (!is_file($fixturePath)) {
            throw new RuntimeException('Fixture file does not exist.', 1768482501);
        }

        $fixture = Yaml::parseFile($fixturePath, Yaml::PARSE_CONSTANT);
        if (!is_array($fixture) || !isset($fixture['variants']) || !is_array($fixture['variants'])) {
            throw new RuntimeException('Fixture file must contain a top-level "variants" map.', 1768482502);
        }

        $this->assertVariantNamesAreUnique($fixture['variants'], $slotNames);
        if (!array_key_exists($variantName, $fixture['variants'])) {
            throw new RuntimeException('The variant "' . $variantName . '" does not exist.', 1768482503);
        }

        $slots = [];
        foreach ($slotNames as $slotName) {
            $path = $this->getSlotPath($resolverDelegate, $componentName, $variantName, $slotName);
            if (!is_file($path)) {
                $slots[$slotName] = '';
                continue;
            }

            $content = file_get_contents($path);
            if ($content === false) {
                throw new RuntimeException('Slot file could not be read.', 1768482504);
            }

            $slots[$slotName] = $content;
        }

        return $slots;
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

    /**
     * @param array<string, mixed> $fixture
     * @param list<string> $slotNames
     */
    private function dumpFixture(array $fixture, array $slotNames): string
    {
        return ($slotNames === [] ? '' : "# slots can be put there: _slots/<variant_name>__slot__<slot_name>.fluid.html\n")
            . Yaml::dump($fixture, 99, 2);
    }

    /**
     * @param array<string, mixed> $slotValues
     * @param list<string> $slotNames
     */
    private function writeSlotFiles(
        ComponentTemplateResolverInterface $resolverDelegate,
        string $componentName,
        string $variantName,
        array $slotValues,
        array $slotNames,
    ): void {
        $this->assertSlotNamesAreUnique($slotNames);
        foreach ($slotValues as $slotName => $content) {
            if (!is_string($content) || !in_array($slotName, $slotNames, true)) {
                throw new InvalidArgumentException('Invalid component slot value.', 1768482505);
            }
        }

        foreach ($slotNames as $slotName) {
            $path = $this->getSlotPath($resolverDelegate, $componentName, $variantName, $slotName);
            $content = $slotValues[$slotName] ?? '';
            if ($content === '') {
                if (is_file($path) && !unlink($path)) {
                    throw new RuntimeException('Slot file could not be deleted.', 1768482506);
                }

                continue;
            }

            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Slot directory could not be created.', 1768482507);
            }

            if (file_put_contents($path, $content) === false) {
                throw new RuntimeException('Slot file could not be written.', 1768482508);
            }
        }
    }

    /**
     * @param array<string, mixed> $variants
     * @param list<string> $slotNames
     */
    private function assertVariantNamesAreUnique(array $variants, array $slotNames): void
    {
        if ($slotNames === []) {
            return;
        }

        $names = [];
        foreach (array_keys($variants) as $variantName) {
            $normalizedName = self::normalizeSlotFilenameSegment((string)$variantName);
            if (isset($names[$normalizedName])) {
                throw new InvalidArgumentException('Variant names "' . $names[$normalizedName] . '" and "' . $variantName . '" use the same slot filename.', 1768482509);
            }

            $names[$normalizedName] = (string)$variantName;
        }
    }

    /**
     * @param list<string> $slotNames
     */
    private function assertSlotNamesAreUnique(array $slotNames): void
    {
        $names = [];
        foreach ($slotNames as $slotName) {
            $normalizedName = self::normalizeSlotFilenameSegment($slotName);
            if (isset($names[$normalizedName])) {
                throw new InvalidArgumentException('Slot names "' . $names[$normalizedName] . '" and "' . $slotName . '" use the same filename.', 1768482510);
            }

            $names[$normalizedName] = $slotName;
        }
    }

    /**
     * @param array<string, mixed>|null $slotValues
     * @param list<string> $slotNames
     */
    private function copySlotFiles(
        ComponentTemplateResolverInterface $resolverDelegate,
        string $componentName,
        string $sourceVariantName,
        string $newVariantName,
        ?array $slotValues,
        array $slotNames,
    ): void {
        if ($slotNames === []) {
            return;
        }

        if ($slotValues !== null) {
            foreach ($slotNames as $slotName) {
                $path = $this->getSlotPath($resolverDelegate, $componentName, $newVariantName, $slotName);
                if (is_file($path)) {
                    throw new RuntimeException('Target slot file already exists.', 1768482511);
                }
            }

            $this->writeSlotFiles($resolverDelegate, $componentName, $newVariantName, $slotValues, $slotNames);
            return;
        }

        $sourceFiles = $this->getVariantSlotFiles($resolverDelegate, $componentName, $sourceVariantName);
        $targetFiles = [];
        foreach ($sourceFiles as $sourceFile) {
            $targetFile = $this->getSlotDirectory($resolverDelegate, $componentName) . '/' . self::normalizeSlotFilenameSegment($newVariantName) . '__slot__' . substr($sourceFile, strrpos($sourceFile, '__slot__') + strlen('__slot__'));
            if (is_file($targetFile)) {
                throw new RuntimeException('Target slot file already exists.', 1768482512);
            }

            $targetFiles[$sourceFile] = $targetFile;
        }

        foreach ($targetFiles as $sourceFile => $targetFile) {
            $directory = dirname($targetFile);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Slot directory could not be created.', 1768482513);
            }

            if (!copy($sourceFile, $targetFile)) {
                throw new RuntimeException('Slot file could not be copied.', 1768482514);
            }
        }
    }

    private function renameSlotFiles(
        ComponentTemplateResolverInterface $resolverDelegate,
        string $componentName,
        string $currentVariantName,
        string $newVariantName,
    ): void {
        $currentPrefix = self::normalizeSlotFilenameSegment($currentVariantName) . '__slot__';
        $newPrefix = self::normalizeSlotFilenameSegment($newVariantName) . '__slot__';
        if ($currentPrefix === $newPrefix) {
            return;
        }

        $renamedFiles = [];
        foreach ($this->getVariantSlotFiles($resolverDelegate, $componentName, $currentVariantName) as $sourceFile) {
            $targetFile = dirname($sourceFile) . '/' . $newPrefix . substr($sourceFile, strrpos($sourceFile, '__slot__') + strlen('__slot__'));
            if (is_file($targetFile)) {
                throw new RuntimeException('Target slot file already exists.', 1768482515);
            }

            $renamedFiles[$sourceFile] = $targetFile;
        }

        foreach ($renamedFiles as $sourceFile => $targetFile) {
            if (!rename($sourceFile, $targetFile)) {
                throw new RuntimeException('Slot file could not be renamed.', 1768482516);
            }
        }
    }

    private function deleteSlotFiles(ComponentTemplateResolverInterface $resolverDelegate, string $componentName, string $variantName): void
    {
        foreach ($this->getVariantSlotFiles($resolverDelegate, $componentName, $variantName) as $path) {
            if (!unlink($path)) {
                throw new RuntimeException('Slot file could not be deleted.', 1768482517);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function getVariantSlotFiles(ComponentTemplateResolverInterface $resolverDelegate, string $componentName, string $variantName): array
    {
        $directory = $this->getSlotDirectory($resolverDelegate, $componentName);
        if (!is_dir($directory)) {
            return [];
        }

        $prefix = self::normalizeSlotFilenameSegment($variantName) . '__slot__';
        $files = [];
        foreach (scandir($directory) ?: [] as $filename) {
            if (!str_starts_with($filename, $prefix) || !str_ends_with($filename, '.fluid.html')) {
                continue;
            }

            $path = $directory . '/' . $filename;
            if (is_file($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    private function getSlotPath(ComponentTemplateResolverInterface $resolverDelegate, string $componentName, string $variantName, string $slotName): string
    {
        return $this->getSlotDirectory($resolverDelegate, $componentName)
            . '/' . self::normalizeSlotFilenameSegment($variantName)
            . '__slot__'
            . self::normalizeSlotFilenameSegment($slotName)
            . '.fluid.html';
    }

    private function getSlotDirectory(ComponentTemplateResolverInterface $resolverDelegate, string $componentName): string
    {
        $templatePath = $this->resolveTemplatePath($resolverDelegate, $componentName);
        if ($templatePath === null) {
            throw new RuntimeException('Component template path could not be resolved.', 1768482518);
        }

        if (str_ends_with($templatePath, '.fluid.html')) {
            return dirname($templatePath) . '/_slots';
        }

        if (str_ends_with($templatePath, '.html')) {
            return dirname($templatePath) . '/_slots';
        }

        throw new RuntimeException('Slot path could not be derived from template path "' . $templatePath . '".', 1768482519);
    }

    public static function normalizeSlotFilenameSegment(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F<>:"\/\\\\|?*]+/', '-', $name) ?? $name;

        return preg_replace('/-+/', '-', $name) ?? $name;
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
