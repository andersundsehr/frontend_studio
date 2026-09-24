<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service;

use Andersundsehr\FrontendStudio\Service\ComponentDiscoveryProvider;
use Andersundsehr\FrontendStudio\Service\ComponentResolverDelegateProvider;
use Andersundsehr\FrontendStudio\Service\ComponentTemplateRootWatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolver;
use TYPO3\CMS\Fluid\Core\ViewHelper\ViewHelperResolverFactoryInterface;
use TYPO3\CMS\Fluid\View\TemplatePaths;
use TYPO3Fluid\Fluid\Core\Component\ComponentListProviderInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentTemplateResolverInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\UnresolvableViewHelperException;
use TYPO3Fluid\Fluid\Core\ViewHelper\ViewHelperResolverDelegateInterface;

#[CoversClass(ComponentTemplateRootWatcher::class)]
final class ComponentTemplateRootWatcherTest extends TestCase
{
    private string $templateRoot;

    protected function setUp(): void
    {
        $templateRoot = tempnam(Environment::getVarPath(), 'frontend-studio-');
        self::assertNotFalse($templateRoot);
        unlink($templateRoot);
        mkdir($templateRoot);
        mkdir($templateRoot . '/Card');
        mkdir($templateRoot . '/Teaser');
        file_put_contents($templateRoot . '/Card/Card.html', '<article>Card</article>');
        file_put_contents($templateRoot . '/Teaser/Teaser.html', '<article>Teaser</article>');

        $this->templateRoot = $templateRoot;
    }

    protected function tearDown(): void
    {
        @unlink($this->templateRoot . '/Card/_slots/Default__slot__default.fluid.html');
        @rmdir($this->templateRoot . '/Card/_slots');
        @unlink($this->templateRoot . '/Card/Card.html');
        @unlink($this->templateRoot . '/Teaser/Teaser.html');
        @rmdir($this->templateRoot . '/Card');
        @rmdir($this->templateRoot . '/Teaser');
        @rmdir($this->templateRoot);
    }

    public function testSlotFileLifecycleRefreshesOwningComponent(): void
    {
        $watcher = $this->createWatcher();
        $slotDirectory = $this->templateRoot . '/Card/_slots';
        $slotPath = $slotDirectory . '/Default__slot__default.fluid.html';

        $initialSnapshot = $watcher->createSnapshot();
        mkdir($slotDirectory);
        file_put_contents($slotPath, '<p>Initial</p>');

        $createdSnapshot = $watcher->createSnapshot();
        self::assertSame(['test:Card'], $watcher->getChangedComponentIdentifiers($initialSnapshot, $createdSnapshot));

        file_put_contents($slotPath, '<p>Changed slot content</p>');

        $changedSnapshot = $watcher->createSnapshot();
        self::assertSame(['test:Card'], $watcher->getChangedComponentIdentifiers($createdSnapshot, $changedSnapshot));

        unlink($slotPath);
        rmdir($slotDirectory);

        $deletedSnapshot = $watcher->createSnapshot();
        self::assertSame(['test:Card'], $watcher->getChangedComponentIdentifiers($changedSnapshot, $deletedSnapshot));
    }

    private function createWatcher(): ComponentTemplateRootWatcher
    {
        $templatePaths = new TemplatePaths();
        $templatePaths->setTemplateRootPaths([$this->templateRoot]);
        $resolverDelegate = new class ($templatePaths) implements ComponentListProviderInterface, ComponentTemplateResolverInterface, ViewHelperResolverDelegateInterface {
            public function __construct(private TemplatePaths $templatePaths)
            {
            }

            public function getAvailableComponents(): array
            {
                return ['Card', 'Teaser'];
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
                return $viewHelperName . '/' . $viewHelperName;
            }

            public function resolveViewHelperClassName(string $name): string
            {
                throw new UnresolvableViewHelperException('Test component resolver does not resolve ViewHelpers.');
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

        return new ComponentTemplateRootWatcher(
            new ComponentResolverDelegateProvider($viewHelperResolverFactory),
            $viewHelperResolverFactory,
            new ComponentDiscoveryProvider(),
        );
    }
}
