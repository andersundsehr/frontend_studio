<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service;

use Andersundsehr\FrontendStudio\Dto\ComponentFixtureMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantValueMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantValues;
use Andersundsehr\FrontendStudio\Service\ComponentFixtureProvider;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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

    private function createProvider(): ComponentFixtureProvider
    {
        $packageManager = $this->createStub(PackageManager::class);
        $packageManager->method('getActivePackages')->willReturn([]);

        return new ComponentFixtureProvider($packageManager);
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
