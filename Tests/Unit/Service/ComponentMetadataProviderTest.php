<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service;

use RuntimeException;
use Andersundsehr\FrontendStudio\Service\ComponentDiscoveryProvider;
use Andersundsehr\FrontendStudio\Service\ComponentFixtureProvider;
use Andersundsehr\FrontendStudio\Service\ComponentMetadataProvider;
use Andersundsehr\FrontendStudio\Service\ComponentResolverDelegateProvider;
use Andersundsehr\FrontendStudio\Transformer\TransformerFactory;
use Andersundsehr\FrontendStudio\Transformer\TransformersFactory;
use Andersundsehr\FrontendStudio\Transformer\TypeTransformers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Package\PackageInterface;
use TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolver;
use TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolverFactoryInterface;
use TYPO3\CMS\Fluid\View\TemplatePaths;
use TYPO3Fluid\Fluid\Core\Component\ComponentDefinition;
use TYPO3Fluid\Fluid\Core\Component\ComponentDefinitionProviderInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentListProviderInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentRenderer;
use TYPO3Fluid\Fluid\Core\Component\ComponentRendererInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentTemplateResolverInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\UnresolvableViewHelperException;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperResolverDelegateInterface;

#[CoversClass(ComponentMetadataProvider::class)]
final class ComponentMetadataProviderTest extends TestCase
{
    private string $templatePath;

    protected function setUp(): void
    {
        $directory = tempnam(sys_get_temp_dir(), 'frontend-studio-');
        self::assertNotFalse($directory);
        unlink($directory);
        mkdir($directory);

        $this->templatePath = $directory . '/Card.html';
        file_put_contents($this->templatePath, '<article>Card</article>');
        file_put_contents($directory . '/Card.fixture.yaml', "variants:\n  Default: []\n");
    }

    protected function tearDown(): void
    {
        foreach (glob(dirname($this->templatePath) . '/_slots/*') ?: [] as $slotPath) {
            @unlink($slotPath);
        }

        @rmdir(dirname($this->templatePath) . '/_slots');
        @unlink($this->templatePath);
        @unlink(dirname($this->templatePath) . '/Card.fixture.yaml');
        @rmdir(dirname($this->templatePath));
    }

    public function testLoadsSelectedVariantSlots(): void
    {
        mkdir(dirname($this->templatePath) . '/_slots');
        file_put_contents($this->getSlotPath(), '<strong>Slot HTML</strong>');

        $metadata = $this->createProvider(['content', 'footer'])->getComponentMetadataForVariantIdentifier('test:Card:Default');

        self::assertNotNull($metadata);
        self::assertSame(['content', 'footer'], $metadata->slots);
        self::assertSame('EXT:test/Card.html', $metadata->template->relativePath);
        self::assertNotNull($metadata->fixture?->selectedVariant);
        self::assertSame(
            ['content' => '<strong>Slot HTML</strong>', 'footer' => ''],
            $metadata->fixture->selectedVariant->slots,
        );
        self::assertSame([], $metadata->errors);
    }

    public function testExposesCollidingSlotNamesAsMetadataError(): void
    {
        $metadata = $this->createProvider(['content/body', 'content\\body'])->getComponentMetadataForVariantIdentifier('test:Card:Default');

        self::assertNotNull($metadata);
        self::assertSame(['content/body', 'content\\body'], $metadata->slots);
        self::assertNotNull($metadata->fixture?->selectedVariant);
        self::assertSame([], $metadata->fixture->selectedVariant->slots);
        self::assertSame(
            ['Component slots could not be loaded: Slot names "content/body" and "content\\body" use the same filename.'],
            $metadata->errors,
        );
    }

    public function testThrowsWhenCollectionDoesNotResolveTemplates(): void
    {
        $resolverDelegate = new readonly class implements ComponentListProviderInterface, ViewHelperResolverDelegateInterface {
            public function getAvailableComponents(): array
            {
                return ['Card'];
            }

            public function resolveViewHelperClassName(string $name): string
            {
                throw new UnresolvableViewHelperException('Test component resolver does not resolve ViewHelpers.', 9606519065);
            }

            public function getNamespace(): string
            {
                return self::class;
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The component collection does not provide template metadata.');

        $this->createProvider([], resolverDelegate: $resolverDelegate)->getComponentMetadataForVariantIdentifier('test:Card:Default');
    }

    public function testThrowsWhenTemplateHasNoRelativePath(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No project-relative or EXT: path is available.');

        $this->createProvider([], mapTemplatePath: false)->getComponentMetadataForVariantIdentifier('test:Card:Default');
    }

    public function testThrowsWhenTemplateFileIsMissing(): void
    {
        unlink($this->templatePath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist.');

        $this->createProvider([])->getComponentMetadataForVariantIdentifier('test:Card:Default');
    }

    /**
     * @param list<string> $slotNames
     */
    private function createProvider(
        array $slotNames,
        bool $mapTemplatePath = true,
        ?ViewHelperResolverDelegateInterface $resolverDelegate = null,
    ): ComponentMetadataProvider {
        $templatePaths = new TemplatePaths();
        $templatePaths->setTemplatePathAndFilename($this->templatePath);

        $resolverDelegate ??= new readonly class ($templatePaths, $slotNames) implements
            ComponentDefinitionProviderInterface,
            ComponentListProviderInterface,
            ComponentTemplateResolverInterface,
            ViewHelperResolverDelegateInterface
        {
            /**
             * @param list<string> $slotNames
             */
            public function __construct(private TemplatePaths $templatePaths, private array $slotNames)
            {
            }

            public function getComponentDefinition(string $viewHelperName): ComponentDefinition
            {
                return new ComponentDefinition('Card', [], false, $this->slotNames);
            }

            public function getComponentRenderer(): ComponentRendererInterface
            {
                return new ComponentRenderer($this);
            }

            public function getAvailableComponents(): array
            {
                return ['Card'];
            }

            public function getTemplatePaths(): TemplatePaths
            {
                return $this->templatePaths;
            }

            public function getAdditionalVariables(string $viewHelperName): array
            {
                return [];
            }

            public function resolveTemplateName(string $viewHelperName): string
            {
                return 'Card';
            }

            public function resolveViewHelperClassName(string $name): string
            {
                throw new UnresolvableViewHelperException('Test component resolver does not resolve ViewHelpers.', 9606519065);
            }

            public function getNamespace(): string
            {
                return self::class;
            }
        };
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $viewHelperResolver = new ViewHelperResolver(
            $container,
            ['test' => [$resolverDelegate::class]],
            [$resolverDelegate::class => $resolverDelegate],
        );
        $viewHelperResolverFactory = $this->createStub(ViewHelperResolverFactoryInterface::class);
        $viewHelperResolverFactory->method('create')->willReturn($viewHelperResolver);
        $packageManager = $this->createStub(PackageManager::class);
        $package = $this->createStub(PackageInterface::class);
        $package->method('getPackagePath')->willReturn(dirname($this->templatePath));
        $package->method('getPackageKey')->willReturn('test');
        $packageManager->method('getActivePackages')->willReturn($mapTemplatePath ? ['test' => $package] : []);
        $transformerFactory = new TransformerFactory($container);

        return new ComponentMetadataProvider(
            new ComponentResolverDelegateProvider($viewHelperResolverFactory),
            $viewHelperResolverFactory,
            $packageManager,
            new ComponentFixtureProvider($packageManager),
            new ComponentDiscoveryProvider(),
            new TransformersFactory(new TypeTransformers($transformerFactory), $transformerFactory),
        );
    }

    private function getSlotPath(): string
    {
        return dirname($this->templatePath) . '/_slots/Default__slot__content.fluid.html';
    }
}
