<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Controller;

use Andersundsehr\FrontendStudio\Controller\ComponentTreeController;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantValues;
use Andersundsehr\FrontendStudio\Service\ComponentDiscoveryProvider;
use Andersundsehr\FrontendStudio\Service\ComponentFixtureProvider;
use Andersundsehr\FrontendStudio\Service\ComponentFolderArchiveProvider;
use Andersundsehr\FrontendStudio\Service\ComponentResolverDelegateProvider;
use Andersundsehr\FrontendStudio\Service\ComponentTreeDataProvider;
use Andersundsehr\FrontendStudio\Transformer\TransformerFactory;
use Andersundsehr\FrontendStudio\Transformer\TransformersFactory;
use Andersundsehr\FrontendStudio\Transformer\TypeTransformers;
use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use ReflectionClass;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Package\PackageManager;
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

#[CoversClass(ComponentTreeController::class)]
#[CoversClass(ComponentTreeDataProvider::class)]
final class ComponentVariantLifecycleTest extends TestCase
{
    private string $templatePath;

    protected function setUp(): void
    {
        $directory = tempnam(sys_get_temp_dir(), 'frontend-studio-');
        self::assertNotFalse($directory);
        unlink($directory);
        mkdir($directory);

        $this->templatePath = $directory . '/Card.html';
        file_put_contents($this->templatePath, '<article><f:slot name="content" /></article>');
    }

    protected function tearDown(): void
    {
        foreach (glob(dirname($this->templatePath) . '/_slots/*') ?: [] as $slotPath) {
            @unlink($slotPath);
        }

        @rmdir(dirname($this->templatePath) . '/_slots');
        @unlink($this->templatePath);
        @unlink(substr($this->templatePath, 0, -strlen('.html')) . '.fixture.yaml');
        @rmdir(dirname($this->templatePath));
    }

    public function testProviderForwardsDeclaredSlotsThroughVariantLifecycle(): void
    {
        $provider = $this->createDataProvider();

        $provider->createVariant('test:Card', 'Default');
        self::assertStringStartsWith(
            '# slots can be put there: _slots/<variant_name>__slot__<slot_name>.fluid.html',
            (string)file_get_contents($this->getFixturePath()),
        );

        $provider->updateVariantValues('test:Card:Default', ComponentVariantValues::empty(), ['content' => '<p>Saved</p>']);
        self::assertSame('<p>Saved</p>', file_get_contents($this->getSlotPath('Default')));

        $provider->copyVariant('test:Card:Default', 'Copy', ComponentVariantValues::empty(), ['content' => '<strong>Copied</strong>']);
        self::assertSame('<strong>Copied</strong>', file_get_contents($this->getSlotPath('Copy')));

        $provider->renameVariant('test:Card:Copy', 'Renamed');
        self::assertFileDoesNotExist($this->getSlotPath('Copy'));
        self::assertSame('<strong>Copied</strong>', file_get_contents($this->getSlotPath('Renamed')));

        $provider->deleteVariant('test:Card:Renamed');
        self::assertFileDoesNotExist($this->getSlotPath('Renamed'));
    }

    /**
     * @throws JsonException
     */
    public function testControllerForwardsSubmittedSlotsToVariantActions(): void
    {
        $provider = $this->createDataProvider();
        $provider->createVariant('test:Card', 'Default');

        $controller = $this->createController($provider);

        $updateResponse = $controller->updateVariantValuesAction(
            new ServerRequest('https://example.test/')->withParsedBody([
                'identifier' => 'test:Card:Default',
                'values' => [],
                'slots' => ['content' => '<em>Updated</em>'],
            ]),
        );
        $updatePayload = $this->decodeResponse($updateResponse);
        self::assertTrue($updatePayload['success']);
        self::assertIsArray($updatePayload['variant']);
        self::assertSame('<em>Updated</em>', file_get_contents($this->getSlotPath('Default')));

        $copyResponse = $controller->copyVariantAction(
            new ServerRequest('https://example.test/')->withParsedBody([
                'identifier' => 'test:Card:Default',
                'name' => 'Copy',
                'values' => [],
                'slots' => ['content' => '<strong>Copied</strong>'],
            ]),
        );
        $copyPayload = $this->decodeResponse($copyResponse);
        self::assertTrue($copyPayload['success']);
        self::assertIsArray($copyPayload['variant']);
        self::assertSame('<strong>Copied</strong>', file_get_contents($this->getSlotPath('Copy')));
    }

    private function createDataProvider(): ComponentTreeDataProvider
    {
        $templatePaths = new TemplatePaths();
        $templatePaths->setTemplatePathAndFilename($this->templatePath);

        $resolverDelegate = new readonly class ($templatePaths) implements
            ComponentDefinitionProviderInterface,
            ComponentListProviderInterface,
            ComponentTemplateResolverInterface,
            ViewHelperResolverDelegateInterface
        {
            public function __construct(private TemplatePaths $templatePaths)
            {
            }

            public function getComponentDefinition(string $viewHelperName): ComponentDefinition
            {
                return new ComponentDefinition('Card', [], false, ['content']);
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
                throw new UnresolvableViewHelperException('Test component resolver does not resolve ViewHelpers.', 2676989740);
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
        $packageManager->method('getActivePackages')->willReturn([]);
        $transformerFactory = new TransformerFactory($container);

        return new ComponentTreeDataProvider(
            new ComponentResolverDelegateProvider($viewHelperResolverFactory),
            $viewHelperResolverFactory,
            new ComponentFixtureProvider($packageManager),
            new ComponentDiscoveryProvider(),
            new TransformersFactory(new TypeTransformers($transformerFactory), $transformerFactory),
        );
    }

    private function createController(ComponentTreeDataProvider $provider): ComponentTreeController
    {
        return new ComponentTreeController(
            $provider,
            new ReflectionClass(ComponentFolderArchiveProvider::class)->newInstanceWithoutConstructor(),
            $this->createStub(ResponseFactoryInterface::class),
            $this->createStub(StreamFactoryInterface::class),
        );
    }

    /**
     * @return array<string, mixed>
     * @throws JsonException
     */
    private function decodeResponse(ResponseInterface $response): array
    {
        return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function getFixturePath(): string
    {
        return substr($this->templatePath, 0, -strlen('.html')) . '.fixture.yaml';
    }

    private function getSlotPath(string $variantName): string
    {
        return dirname($this->templatePath) . '/_slots/' . $variantName . '__slot__content.fluid.html';
    }
}
