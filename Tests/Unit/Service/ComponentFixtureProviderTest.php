<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service;

use Andersundsehr\FrontendStudio\Dto\ComponentFixtureMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantValueMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantValues;
use Andersundsehr\FrontendStudio\Service\ComponentFixtureProvider;
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
        $this->templatePath = tempnam(sys_get_temp_dir(), 'frontend-studio-') . '.html';
        file_put_contents($this->templatePath, '<f:variable name="example" />');
    }

    protected function tearDown(): void
    {
        @unlink($this->templatePath);
        @unlink(substr($this->templatePath, 0, -strlen('.html')) . '.fixture.yaml');
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

    public function testReturnsEmptyTypedMetadataWhenFixtureDoesNotExist(): void
    {
        $metadata = $this->createProvider()->getFixtureMetadata($this->createResolverDelegate(), 'Card');

        self::assertSame([], $metadata->variants);
        self::assertNull($metadata->content);
        self::assertNull($metadata->error);
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
