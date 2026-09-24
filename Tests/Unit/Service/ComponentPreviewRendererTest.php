<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service;

use Andersundsehr\FrontendStudio\Service\ComponentDiscoveryProvider;
use Andersundsehr\FrontendStudio\Service\ComponentFixtureProvider;
use Andersundsehr\FrontendStudio\Service\ComponentPreviewRenderer;
use Andersundsehr\FrontendStudio\Service\ComponentResolverDelegateProvider;
use Andersundsehr\FrontendStudio\Service\ComponentVariantTransformer;
use Andersundsehr\FrontendStudio\Transformer\TransformerFactory;
use Andersundsehr\FrontendStudio\Transformer\TransformersFactory;
use Andersundsehr\FrontendStudio\Transformer\TypeTransformers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\Backend\NullBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\DependencyInjection\FailsafeContainer;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Fluid\Core\Cache\FluidTemplateCache;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextFactory;
use TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolver;
use TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolverFactoryInterface;
use TYPO3\CMS\Fluid\View\TemplatePaths;
use TYPO3Fluid\Fluid\Core\Component\ComponentDefinition;
use TYPO3Fluid\Fluid\Core\Component\ComponentDefinitionProviderInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentListProviderInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentRenderer;
use TYPO3Fluid\Fluid\Core\Component\ComponentRendererInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentTemplateResolverInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\StrictArgumentProcessor;
use TYPO3Fluid\Fluid\Core\ViewHelper\UnresolvableViewHelperException;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperResolverDelegateInterface;

#[CoversClass(ComponentPreviewRenderer::class)]
final class ComponentPreviewRendererTest extends TestCase
{
    private string $templatePath;

    protected function setUp(): void
    {
        $directory = tempnam(sys_get_temp_dir(), 'frontend-studio-');
        self::assertNotFalse($directory);
        unlink($directory);
        mkdir($directory);

        $this->templatePath = $directory . '/Card.html';
        file_put_contents($this->templatePath, '<article><f:slot /></article>');
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

    public function testRendersSavedSlotHtmlRaw(): void
    {
        $this->writeSlot('<strong>Saved</strong>');

        $renderer = $this->createRenderer();
        $result = $renderer->renderVariant('test:Card:Default', $this->createStub(ServerRequestInterface::class));

        self::assertSame('<article><strong>Saved</strong></article>', $result);
        self::assertStringNotContainsString('&lt;strong&gt;', $result);
    }

    public function testRendersUnsavedSlotHtmlRaw(): void
    {
        $this->writeSlot('<strong>Saved</strong>');

        $result = $this->createRenderer()->renderVariant(
            'test:Card:Default',
            $this->createStub(ServerRequestInterface::class),
            null,
            ['default' => '<em>Draft</em>'],
        );

        self::assertSame('<article><em>Draft</em></article>', $result);
        self::assertStringNotContainsString('&lt;em&gt;', $result);
        self::assertSame('<strong>Saved</strong>', file_get_contents($this->getSlotPath()));
    }

    private function createRenderer(): ComponentPreviewRenderer
    {
        $templatePaths = new TemplatePaths();
        $templatePaths->setTemplatePathAndFilename($this->templatePath);
        $resolverDelegate = new class ($templatePaths) implements
            ComponentDefinitionProviderInterface,
            ComponentListProviderInterface,
            ComponentTemplateResolverInterface,
            ViewHelperResolverDelegateInterface
        {
            private ComponentRendererInterface $componentRenderer;

            public function __construct(private TemplatePaths $templatePaths)
            {
                $this->componentRenderer = new ComponentRenderer($this);
            }

            public function getComponentDefinition(string $viewHelperName): ComponentDefinition
            {
                return new ComponentDefinition('Card', [], false, ['default']);
            }

            public function getComponentRenderer(): ComponentRendererInterface
            {
                return $this->componentRenderer;
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
                throw new UnresolvableViewHelperException('Test component resolver only renders components.');
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
            [
                'f' => ['TYPO3Fluid\\Fluid\\ViewHelpers'],
                'test' => [$resolverDelegate::class],
            ],
            [$resolverDelegate::class => $resolverDelegate],
        );
        $viewHelperResolverFactory = $this->createStub(ViewHelperResolverFactoryInterface::class);
        $viewHelperResolverFactory->method('create')->willReturn($viewHelperResolver);

        $cacheManager = new CacheManager();
        $cacheManager->registerCache(new FluidTemplateCache('fluid_template', new NullBackend()));
        $renderingContextFactory = new RenderingContextFactory(
            new FailsafeContainer(),
            $cacheManager,
            $viewHelperResolverFactory,
            new StrictArgumentProcessor(),
        );
        $packageManager = $this->createStub(PackageManager::class);
        $packageManager->method('getActivePackages')->willReturn([]);
        $fixtureProvider = new ComponentFixtureProvider($packageManager);
        $transformerFactory = new TransformerFactory($container);

        return new ComponentPreviewRenderer(
            new ComponentResolverDelegateProvider($viewHelperResolverFactory),
            $viewHelperResolverFactory,
            $renderingContextFactory,
            $fixtureProvider,
            new ComponentDiscoveryProvider(),
            new TransformersFactory(new TypeTransformers($transformerFactory), $transformerFactory),
            new ComponentVariantTransformer(),
        );
    }

    private function writeSlot(string $content): void
    {
        mkdir(dirname($this->getSlotPath()));
        file_put_contents($this->getSlotPath(), $content);
    }

    private function getSlotPath(): string
    {
        return dirname($this->templatePath) . '/_slots/Default__slot__default.fluid.html';
    }
}
