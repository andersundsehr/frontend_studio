<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service;

use Andersundsehr\FrontendStudio\Dto\ComponentFixtureMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantValueMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantValues;
use Andersundsehr\FrontendStudio\Service\ComponentFixtureProvider;
use InvalidArgumentException;
use Andersundsehr\FrontendStudio\Service\ComponentWriteDeniedException;
use ReflectionProperty;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\ApplicationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3Fluid\Fluid\Core\Component\ComponentTemplateResolverInterface;
use TYPO3Fluid\Fluid\View\TemplatePaths;

#[CoversClass(ComponentFixtureProvider::class)]
#[CoversClass(ComponentFixtureMetadata::class)]
#[CoversClass(ComponentVariantMetadata::class)]
#[CoversClass(ComponentVariantValueMetadata::class)]
#[CoversClass(ComponentVariantValues::class)]
final class ComponentFixtureProviderTest extends TestCase
{
    private string $templatePath;

    protected function setUp(): void
    {
        $directory = tempnam(sys_get_temp_dir(), 'frontend-studio-');
        self::assertNotFalse($directory);
        unlink($directory);
        mkdir($directory);
        $this->templatePath = $directory . '/Card.html';
        file_put_contents($this->templatePath, '<f:variable name="example" />');
    }

    protected function tearDown(): void
    {
        @unlink($this->templatePath);
        @unlink(substr($this->templatePath, 0, -strlen('.html')) . '.fixture.yaml');
        foreach (glob(dirname($this->templatePath) . '/_slots/*') ?: [] as $slotFile) {
            @unlink($slotFile);
        }

        @rmdir(dirname($this->templatePath) . '/_slots');
        @rmdir(dirname($this->templatePath));
    }

    public function testReturnsTypedFixtureMetadata(): void
    {
        file_put_contents(substr($this->templatePath, 0, -strlen('.html')) . '.fixture.yaml', <<<YAML
variants:
  Default:
    title: Hello
    enabled: true
YAML);

        $metadata = $this->createProvider()->getFixtureMetadata($this->createResolverDelegate(), 'Card');

        self::assertInstanceOf(ComponentFixtureMetadata::class, $metadata);
        self::assertSame('Default', $metadata->variants[0]->name);
        self::assertSame('Hello', $metadata->variants[0]->values[0]->nativeValue);
        self::assertTrue($metadata->variants[0]->values[1]->nativeValue);
    }

    public function testKeepsYamlNullDistinctFromStringNull(): void
    {
        file_put_contents(substr($this->templatePath, 0, -strlen('.html')) . '.fixture.yaml', <<<YAML
variants:
  Default:
    nativeNull: null
    stringNull: 'null'
YAML);

        $values = $this->createProvider()->getFixtureMetadata($this->createResolverDelegate(), 'Card')->variants[0]->values;

        self::assertNull($values[0]->nativeValue);
        self::assertSame('', $values[0]->value);
        self::assertSame('null', $values[1]->nativeValue);
        self::assertSame('null', $values[1]->value);
    }

    public function testReturnsEmptyTypedMetadataWhenFixtureDoesNotExist(): void
    {
        $metadata = $this->createProvider()->getFixtureMetadata($this->createResolverDelegate(), 'Card');

        self::assertSame([], $metadata->variants);
        self::assertNull($metadata->content);
        self::assertNull($metadata->error);
        self::assertSame([], $this->createProvider()->getPreviewStylesheets($this->createResolverDelegate(), 'Card'));
    }

    public function testReadsAndPreservesSharedPreviewStylesheets(): void
    {
        file_put_contents(substr($this->templatePath, 0, -strlen('.html')) . '.fixture.yaml', <<<YAML
stylesheets:
  - EXT:backend/Resources/Public/Css/backend.css
variants:
  Default: []
YAML);

        $provider = $this->createProvider();
        $resolverDelegate = $this->createResolverDelegate();
        $expected = ['EXT:backend/Resources/Public/Css/backend.css'];

        self::assertSame($expected, $provider->getPreviewStylesheets($resolverDelegate, 'Card'));
        $provider->updateVariantValues($resolverDelegate, 'Card', 'Default', ComponentVariantValues::empty());
        $fixture = Yaml::parseFile(substr($this->templatePath, 0, -strlen('.html')) . '.fixture.yaml');
        self::assertSame($expected, $fixture['stylesheets'] ?? null);
    }

    public function testStoresAndClearsSlotHtmlOutsideTheFixture(): void
    {
        file_put_contents(substr($this->templatePath, 0, -strlen('.html')) . '.fixture.yaml', "variants:\n  Default: []\n");
        $provider = $this->createProvider();
        $resolverDelegate = $this->createResolverDelegate();

        $provider->updateVariantValues(
            $resolverDelegate,
            'Card',
            'Default',
            ComponentVariantValues::empty(),
            ['default' => '<p>Slot HTML</p>'],
            ['default'],
        );

        self::assertSame(['default' => '<p>Slot HTML</p>'], $provider->getVariantSlots($resolverDelegate, 'Card', 'Default', ['default']));
        self::assertStringStartsWith('# slots can be put there: _slots/<variant_name>__slot__<slot_name>.fluid.html', (string)file_get_contents(substr($this->templatePath, 0, -strlen('.html')) . '.fixture.yaml'));
        self::assertStringNotContainsString('<p>Slot HTML</p>', (string)file_get_contents(substr($this->templatePath, 0, -strlen('.html')) . '.fixture.yaml'));

        $provider->updateVariantValues($resolverDelegate, 'Card', 'Default', ComponentVariantValues::empty(), ['default' => ''], ['default']);

        self::assertSame(['default' => ''], $provider->getVariantSlots($resolverDelegate, 'Card', 'Default', ['default']));
    }

    public function testRejectsNonStringSlotValues(): void
    {
        file_put_contents(substr($this->templatePath, 0, -strlen('.html')) . '.fixture.yaml', "variants:\n  Default: []\n");
        $provider = $this->createProvider();
        $resolverDelegate = $this->createResolverDelegate();
        $provider->updateVariantValues($resolverDelegate, 'Card', 'Default', ComponentVariantValues::empty(), ['default' => '<p>Preserved</p>'], ['default']);

        foreach ([null, []] as $value) {
            try {
                $provider->updateVariantValues($resolverDelegate, 'Card', 'Default', ComponentVariantValues::empty(), ['default' => $value], ['default']);
                self::fail('Expected invalid slot value to be rejected.');
            } catch (InvalidArgumentException) {
                self::assertSame(['default' => '<p>Preserved</p>'], $provider->getVariantSlots($resolverDelegate, 'Card', 'Default', ['default']));
            }
        }
    }

    public function testCopiesRenamesAndDeletesSlotFiles(): void
    {
        file_put_contents(substr($this->templatePath, 0, -strlen('.html')) . '.fixture.yaml', "variants:\n  Default: []\n");
        $provider = $this->createProvider();
        $resolverDelegate = $this->createResolverDelegate();
        $provider->updateVariantValues($resolverDelegate, 'Card', 'Default', ComponentVariantValues::empty(), ['default' => '<strong>Copied</strong>'], ['default']);

        $provider->copyVariant($resolverDelegate, 'Card', 'Default', 'Copy', null, [], null, ['default']);
        self::assertSame(['default' => '<strong>Copied</strong>'], $provider->getVariantSlots($resolverDelegate, 'Card', 'Copy', ['default']));

        $provider->renameVariant($resolverDelegate, 'Card', 'Copy', 'Renamed', ['default']);
        self::assertSame(['default' => '<strong>Copied</strong>'], $provider->getVariantSlots($resolverDelegate, 'Card', 'Renamed', ['default']));

        $provider->deleteVariant($resolverDelegate, 'Card', 'Renamed', ['default']);
        self::assertFileDoesNotExist(dirname($this->templatePath) . '/_slots/Renamed__slot__default.fluid.html');
    }

    public function testNormalizesSlotFilenames(): void
    {
        $variantName = 'Default//Variant';
        $slotName = 'content\\body??primary';
        $fixturePath = substr($this->templatePath, 0, -strlen('.html')) . '.fixture.yaml';
        file_put_contents($fixturePath, "variants:\n  'Default//Variant': []\n");
        $provider = $this->createProvider();
        $resolverDelegate = $this->createResolverDelegate();

        $provider->updateVariantValues(
            $resolverDelegate,
            'Card',
            $variantName,
            ComponentVariantValues::empty(),
            [$slotName => '<p>Normalized</p>'],
            [$slotName],
        );

        $slotPath = dirname($this->templatePath) . '/_slots/Default-Variant__slot__content-body-primary.fluid.html';
        self::assertFileExists($slotPath);
        self::assertSame(
            [$slotName => '<p>Normalized</p>'],
            $provider->getVariantSlots($resolverDelegate, 'Card', $variantName, [$slotName]),
        );
    }

    public function testCopiesUnsavedSlotValues(): void
    {
        $fixturePath = substr($this->templatePath, 0, -strlen('.html')) . '.fixture.yaml';
        file_put_contents($fixturePath, "variants:\n  Default: []\n");
        $provider = $this->createProvider();
        $resolverDelegate = $this->createResolverDelegate();
        $provider->updateVariantValues(
            $resolverDelegate,
            'Card',
            'Default',
            ComponentVariantValues::empty(),
            ['default' => '<strong>Saved</strong>'],
            ['default'],
        );

        $provider->copyVariant(
            $resolverDelegate,
            'Card',
            'Default',
            'Copy',
            null,
            [],
            ['default' => '<em>Draft</em>'],
            ['default'],
        );

        self::assertSame(
            ['default' => '<strong>Saved</strong>'],
            $provider->getVariantSlots($resolverDelegate, 'Card', 'Default', ['default']),
        );
        self::assertSame(
            ['default' => '<em>Draft</em>'],
            $provider->getVariantSlots($resolverDelegate, 'Card', 'Copy', ['default']),
        );
    }

    public function testRejectsCopyWhenTargetSlotFileExists(): void
    {
        $fixturePath = substr($this->templatePath, 0, -strlen('.html')) . '.fixture.yaml';
        file_put_contents($fixturePath, "variants:\n  Default: []\n");
        $provider = $this->createProvider();
        $resolverDelegate = $this->createResolverDelegate();
        $provider->updateVariantValues(
            $resolverDelegate,
            'Card',
            'Default',
            ComponentVariantValues::empty(),
            ['default' => '<strong>Saved</strong>'],
            ['default'],
        );
        $this->writeSlotFile('Copy', 'default', '<strong>Existing</strong>');

        try {
            $provider->copyVariant(
                $resolverDelegate,
                'Card',
                'Default',
                'Copy',
                null,
                [],
                null,
                ['default'],
            );
            self::fail('Expected an existing target slot file to prevent copying stored slots.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame(1768482512, $runtimeException->getCode());
        }

        try {
            $provider->copyVariant(
                $resolverDelegate,
                'Card',
                'Default',
                'Copy',
                null,
                [],
                ['default' => '<em>Draft</em>'],
                ['default'],
            );
            self::fail('Expected an existing target slot file to prevent copying unsaved slots.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame(1768482511, $runtimeException->getCode());
        }

        $targetPath = dirname($this->templatePath) . '/_slots/Copy__slot__default.fluid.html';
        self::assertSame('<strong>Existing</strong>', file_get_contents($targetPath));
        $fixture = $provider->getFixtureMetadata($resolverDelegate, 'Card');
        self::assertSame(
            ['Default'],
            array_map(static fn(ComponentVariantMetadata $variant): string => $variant->name, $fixture->variants),
        );
    }

    public function testRejectsRenameWhenTargetSlotFileExists(): void
    {
        $fixturePath = substr($this->templatePath, 0, -strlen('.html')) . '.fixture.yaml';
        file_put_contents($fixturePath, "variants:\n  Default: []\n");
        $provider = $this->createProvider();
        $resolverDelegate = $this->createResolverDelegate();
        $provider->updateVariantValues(
            $resolverDelegate,
            'Card',
            'Default',
            ComponentVariantValues::empty(),
            ['default' => '<strong>Saved</strong>'],
            ['default'],
        );
        $this->writeSlotFile('Renamed', 'default', '<strong>Existing</strong>');

        try {
            $provider->renameVariant($resolverDelegate, 'Card', 'Default', 'Renamed', ['default']);
            self::fail('Expected an existing target slot file to prevent renaming.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame(1768482515, $runtimeException->getCode());
        }

        self::assertSame(
            ['default' => '<strong>Saved</strong>'],
            $provider->getVariantSlots($resolverDelegate, 'Card', 'Default', ['default']),
        );
        $targetPath = dirname($this->templatePath) . '/_slots/Renamed__slot__default.fluid.html';
        self::assertSame('<strong>Existing</strong>', file_get_contents($targetPath));
        $fixture = $provider->getFixtureMetadata($resolverDelegate, 'Card');
        self::assertSame(
            ['Default'],
            array_map(static fn(ComponentVariantMetadata $variant): string => $variant->name, $fixture->variants),
        );
    }

    public function testRejectsCollidingNormalizedSlotNames(): void
    {
        file_put_contents(substr($this->templatePath, 0, -strlen('.html')) . '.fixture.yaml', "variants:\n  Default: []\n");

        $this->expectException(InvalidArgumentException::class);
        $this->createProvider()->getVariantSlots($this->createResolverDelegate(), 'Card', 'Default', ['content/body', 'content\\body']);
    }

    public function testRejectsCollidingNormalizedVariantNames(): void
    {
        file_put_contents(substr($this->templatePath, 0, -strlen('.html')) . '.fixture.yaml', "variants:\n  A/B: []\n  A\\B: []\n");

        $this->expectException(InvalidArgumentException::class);
        $this->createProvider()->getVariantSlots($this->createResolverDelegate(), 'Card', 'A/B', ['default']);
    }

    #[DataProvider('invalidWrappers')]
    public function testInvalidWrapperReportsAnErrorButStillExposesExistingVariants(mixed $wrapper): void
    {
        file_put_contents(substr($this->templatePath, 0, -strlen('.html')) . '.fixture.yaml', Yaml::dump(['wrapper' => $wrapper, 'variants' => ['Default' => [], 'Alternate' => ['title' => 'Alternate preview']]]));
        $provider = $this->createProvider();
        $metadata = $provider->getFixtureMetadata($this->createResolverDelegate(), 'Card');
        self::assertStringContainsString('exactly one {{component}}', $metadata->error ?? '');
        self::assertSame(
            ['Default', 'Alternate'],
            array_map(static fn(ComponentVariantMetadata $variant): string => $variant->name, $metadata->variants),
        );
        $this->expectException(InvalidArgumentException::class);
        $provider->getPreviewWrapper($this->createResolverDelegate(), 'Card');
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidWrappers(): iterable
    {
        yield 'null' => [null];
        yield 'number' => [42];
        yield 'array' => [[]];
        yield 'blank' => [' '];
        yield 'missing placeholder' => ['<section></section>'];
        yield 'duplicate placeholder' => ['{{component}}{{component}}'];
    }

    public function testVariantLifecyclePreservesWrapperAndOtherTopLevelMetadata(): void
    {
        $path = substr($this->templatePath, 0, -strlen('.html')) . '.fixture.yaml';
        $wrapper = '<section>{{component}}</section>';
        file_put_contents($path, Yaml::dump(['wrapper' => $wrapper, 'stylesheets' => ['example.css'], 'custom' => 'retained', 'variants' => ['Default' => []]]));
        $provider = $this->createProvider();
        $resolver = $this->createResolverDelegate();
        $operations = [
            fn(): array => $provider->createVariant($resolver, 'Card', 'New'),
            fn(): array => $provider->copyVariant($resolver, 'Card', 'Default', 'Copy'),
            fn(): array => $provider->renameVariant($resolver, 'Card', 'Copy', 'Renamed'),
            fn(): array => $provider->updateVariantValues($resolver, 'Card', 'Default', ComponentVariantValues::empty()),
            fn(): array => $provider->deleteVariant($resolver, 'Card', 'Renamed'),
        ];
        foreach ($operations as $operation) {
            $operation();
            $fixture = Yaml::parseFile($path);
            self::assertSame($wrapper, $fixture['wrapper']);
            self::assertSame(['example.css'], $fixture['stylesheets']);
            self::assertSame('retained', $fixture['custom']);
        }
    }

    public function testAbsentWrapperLeavesPreviewsUnwrapped(): void
    {
        self::assertNull($this->createProvider()->getPreviewWrapper($this->createResolverDelegate(), 'Card'));
        file_put_contents(substr($this->templatePath, 0, -strlen('.html')) . '.fixture.yaml', "variants:\n  Default: []\n");
        self::assertNull($this->createProvider()->getPreviewWrapper($this->createResolverDelegate(), 'Card'));
    }

    #[DataProvider('writeContexts')]
    public function testDirectServiceWritesFollowApplicationContext(string $context, bool $allowed): void
    {
        $provider = $this->createProvider();
        $resolver = $this->createResolverDelegate();
        $property = new ReflectionProperty(Environment::class, 'context');
        $original = Environment::getContext();
        $property->setValue(null, new ApplicationContext($context));
        try {
            if (!$allowed) {
                $this->expectException(ComponentWriteDeniedException::class);
            }

            $provider->createVariant($resolver, 'Card', 'Default');
            self::assertFileExists(substr($this->templatePath, 0, -strlen('.html')) . '.fixture.yaml');
        } finally {
            $property->setValue(null, $original);
            if (!$allowed) {
                self::assertFileDoesNotExist(substr($this->templatePath, 0, -strlen('.html')) . '.fixture.yaml');
            }
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function writeContexts(): iterable
    {
        yield 'Development' => ['Development', true];
        yield 'Testing' => ['Testing', true];
        yield 'Production' => ['Production', false];
        yield 'Production/Staging' => ['Production/Staging', false];
    }

    private function createProvider(): ComponentFixtureProvider
    {
        $packageManager = $this->createStub(PackageManager::class);
        $packageManager->method('getActivePackages')->willReturn([]);

        return new ComponentFixtureProvider($packageManager);
    }

    private function writeSlotFile(string $variantName, string $slotName, string $content): void
    {
        $directory = dirname($this->templatePath) . '/_slots';
        if (!is_dir($directory)) {
            mkdir($directory);
        }

        file_put_contents($directory . '/' . $variantName . '__slot__' . $slotName . '.fluid.html', $content);
    }

    private function createResolverDelegate(): ComponentTemplateResolverInterface
    {
        $templatePaths = new TemplatePaths();
        $templatePaths->setTemplatePathAndFilename($this->templatePath);

        $resolverDelegate = $this->createStub(ComponentTemplateResolverInterface::class);
        $resolverDelegate->method('getTemplatePaths')->willReturn($templatePaths);
        $resolverDelegate->method('resolveTemplateName')->willReturn('Card');

        return $resolverDelegate;
    }
}
