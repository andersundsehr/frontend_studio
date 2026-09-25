<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service;

use Andersundsehr\FrontendStudio\Service\ComponentDiscoveryProvider;
use Andersundsehr\FrontendStudio\Service\ComponentPathResolver;
use Andersundsehr\FrontendStudio\Service\ComponentResolverDelegateProvider;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolver;
use TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolverFactoryInterface;
use TYPO3\CMS\Fluid\View\TemplatePaths;
use TYPO3Fluid\Fluid\Core\Component\ComponentRenderer;
use TYPO3Fluid\Fluid\Core\Component\ComponentRendererInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentTemplateResolverInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentListProviderInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\UnresolvableViewHelperException;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperResolverDelegateInterface;

#[CoversClass(ComponentPathResolver::class)]
final class ComponentPathResolverTest extends TestCase
{
    private string $templatePath;

    protected function setUp(): void
    {
        $directory = tempnam(sys_get_temp_dir(), 'frontend-studio-');
        self::assertNotFalse($directory);
        unlink($directory);
        mkdir($directory);

        $this->templatePath = $directory . '/Card.fluid.html';
        file_put_contents($this->templatePath, '<article>Card</article>');
    }

    protected function tearDown(): void
    {
        @unlink($this->templatePath);
        @rmdir(dirname($this->templatePath));
    }

    public function testFindsVariantIdentifierByCallerFileDirectory(): void
    {
        $matches = $this->createResolver(['Card'])->findVariantIdentifiers(
            dirname($this->templatePath) . '/Card.spec.ts',
            'Special Variant',
        );

        self::assertSame(['site:Card:Special Variant'], $matches);
    }

    public function testReturnsNoMatchForAnUnregisteredDirectory(): void
    {
        $matches = $this->createResolver(['Card'])->findVariantIdentifiers(
            dirname($this->templatePath) . '/missing/Card.spec.ts',
            'Default',
        );

        self::assertSame([], $matches);
    }

    public function testReturnsEveryMatchWhenMultipleComponentsShareATemplateDirectory(): void
    {
        $matches = $this->createResolver(['Card', 'Duplicate'])->findVariantIdentifiers(
            dirname($this->templatePath) . '/Card.spec.ts',
            'Default',
        );

        self::assertSame(['site:Card:Default', 'site:Duplicate:Default'], $matches);
    }

    public function testRejectsRelativePaths(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createResolver(['Card'])->findVariantIdentifiers('tmp/Card.spec.ts', 'Default');
    }

    /**
     * @param list<string> $componentNames
     */
    private function createResolver(array $componentNames): ComponentPathResolver
    {
        $templatePaths = new TemplatePaths();
        $templatePaths->setTemplatePathAndFilename($this->templatePath);

        $resolverDelegate = new readonly class ($templatePaths, $componentNames) implements
            ComponentListProviderInterface,
            ComponentTemplateResolverInterface,
            ViewHelperResolverDelegateInterface
        {
            /**
             * @param list<string> $componentNames
             */
            public function __construct(private TemplatePaths $templatePaths, private array $componentNames)
            {
            }

            public function getAvailableComponents(): array
            {
                return $this->componentNames;
            }

            public function getComponentRenderer(): ComponentRendererInterface
            {
                return new ComponentRenderer($this);
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
                throw new UnresolvableViewHelperException('Test component resolver only renders components.', 4197300002);
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
            ['site' => [$resolverDelegate::class]],
            [$resolverDelegate::class => $resolverDelegate],
        );
        $viewHelperResolverFactory = $this->createStub(ViewHelperResolverFactoryInterface::class);
        $viewHelperResolverFactory->method('create')->willReturn($viewHelperResolver);

        return new ComponentPathResolver(
            new ComponentResolverDelegateProvider($viewHelperResolverFactory),
            $viewHelperResolverFactory,
            new ComponentDiscoveryProvider(),
        );
    }
}
