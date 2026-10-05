<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service;

use Andersundsehr\FrontendStudio\Dto\ComponentVariantValues;
use RuntimeException;
use Andersundsehr\FrontendStudio\Service\ComponentDiscoveryProvider;
use Andersundsehr\FrontendStudio\Service\ComponentFixtureProvider;
use Andersundsehr\FrontendStudio\Service\ComponentMetadataProvider;
use Andersundsehr\FrontendStudio\Service\ComponentResolverDelegateProvider;
use Andersundsehr\FrontendStudio\Service\FluidUsageSnippetRenderer;
use Andersundsehr\FrontendStudio\Service\HtmlSourceHighlighter;
use Andersundsehr\FrontendStudio\Transformer\Defaults\TypolinkTargetEnum;
use Andersundsehr\FrontendStudio\Transformer\TransformerFactory;
use Andersundsehr\FrontendStudio\Transformer\TransformersFactory;
use Andersundsehr\FrontendStudio\Transformer\TypeTransformers;
use stdClass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Yaml\Yaml;
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
use TYPO3Fluid\Fluid\Core\ViewHelper\ArgumentDefinition;
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
        @unlink(dirname($this->templatePath) . '/Card.transformer.php');
        @rmdir(dirname($this->templatePath));
    }

    #[DataProvider('fixtureValuesDataProvider')]
    public function testInitialUsageMatchesTypedControlUpdates(string $type, string $fixtureValue, int|float|string $expected, string $inlineValue): void
    {
        $fixturePath = dirname($this->templatePath) . '/Card.fixture.yaml';
        $fixture = Yaml::dump(['variants' => ['Default' => ['uid' => $fixtureValue]]]);
        file_put_contents($fixturePath, $fixture);
        $metadata = $this->createProvider([], argumentDefinitions: [
            'uid' => new ArgumentDefinition('uid', $type, 'Identifier', true),
        ])->getComponentMetadataForVariantIdentifier('test:Card:Default');

        self::assertNotNull($metadata);
        self::assertNotNull($metadata->fixture?->selectedVariant);
        $value = $metadata->fixture->selectedVariant->values[0];
        self::assertSame($expected, $value->nativeValue);
        self::assertSame((string)$expected, $value->value);
        self::assertSame($type, $value->type);
        self::assertSame('Identifier', $value->description);
        self::assertTrue($value->required);
        self::assertTrue($value->isFixtureValue);
        self::assertSame([], $metadata->errors);
        $renderer = new FluidUsageSnippetRenderer(new HtmlSourceHighlighter());
        $initialSnippet = $renderer->buildInline($metadata);
        self::assertSame('{test:Card(uid: ' . $inlineValue . ')}', $initialSnippet);
        self::assertSame($initialSnippet, $renderer->buildInline($metadata, ComponentVariantValues::fromSubmittedValues(['uid' => $expected])));
        self::assertSame($initialSnippet, html_entity_decode(strip_tags($renderer->render($metadata)['inline']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        self::assertSame($fixture, file_get_contents($fixturePath));
    }

    /**
     * @return iterable<string, array{string, string, int|float|string, string}>
     */
    public static function fixtureValuesDataProvider(): iterable
    {
        yield 'integer' => ['int', '50', 50, '50'];
        yield 'integer alias' => ['integer', '50', 50, '50'];
        yield 'decimal' => ['float', '1.5', 1.5, '1.5'];
        yield 'float alias' => ['double', '1.5', 1.5, '1.5'];
        yield 'negative integer' => ['int', '-1', -1, "'-1'"];
        yield 'scientific notation' => ['float', '1.0E+20', 1.0E+20, "'1.0E+20'"];
        yield 'string with leading zeros' => ['string', '0050', '0050', "'0050'"];
        yield 'existing enum value' => [TypolinkTargetEnum::class, 'none', 'none', "'none'"];
    }

    public function testNormalizesNumericTransformerMetadataWithoutExecutingTheTransformer(): void
    {
        file_put_contents(dirname($this->templatePath) . '/Card.fixture.yaml', "variants:\n  Default:\n    title:\n      count: '50'\n      ratio: '1.5'\n");
        file_put_contents(dirname($this->templatePath) . '/Card.transformer.php', <<<'PHP'
<?php
return new \Andersundsehr\FrontendStudio\Transformer\ArgumentTransformers(
    title: static fn(int $count, float $ratio): string => throw new \RuntimeException('Metadata must not execute transformers.'),
);
PHP);
        $metadata = $this->createProvider([], argumentDefinitions: [
            'title' => new ArgumentDefinition('title', 'string', '', true),
        ])->getComponentMetadataForVariantIdentifier('test:Card:Default');

        self::assertNotNull($metadata);
        self::assertNotNull($metadata->fixture?->selectedVariant);
        self::assertSame([], $metadata->errors);
        self::assertSame([50, 1.5], array_column($metadata->fixture->selectedVariant->values, 'nativeValue'));
        self::assertSame(['title.count', 'title.ratio'], array_column($metadata->fixture->selectedVariant->values, 'fixtureName'));
        self::assertSame(['title', 'title'], array_column($metadata->fixture->selectedVariant->values, 'parentName'));
        self::assertSame('{test:Card(title: title)}', new FluidUsageSnippetRenderer(new HtmlSourceHighlighter())->buildInline($metadata));
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

    public function testMissingTransformerIsExposedAndClearedAfterAddingArgumentTransformer(): void
    {
        $definitions = ['payload' => new ArgumentDefinition('payload', stdClass::class, '', true)];
        $metadata = $this->createProvider([], argumentDefinitions: $definitions)->getComponentMetadataForVariantIdentifier('test:Card:Default');

        self::assertNotNull($metadata);
        self::assertNotNull($metadata->missingTransformerError);
        self::assertStringContainsString('argument "payload" of type "stdClass"', $metadata->missingTransformerError);
        self::assertStringContainsString(dirname($this->templatePath) . '/Card.transformer.php', $metadata->missingTransformerError);
        self::assertStringContainsString('ArgumentTransformers', $metadata->missingTransformerError);
        self::assertStringContainsString('#[TypeTransformer]', $metadata->missingTransformerError);
        self::assertSame(['Component transformers could not be loaded: ' . $metadata->missingTransformerError], $metadata->errors);

        file_put_contents(dirname($this->templatePath) . '/Card.transformer.php', <<<'PHP'
<?php
return new \Andersundsehr\FrontendStudio\Transformer\ArgumentTransformers(
    payload: static fn(string $value = ''): \stdClass => throw new \RuntimeException('Metadata must not execute transformers.'),
);
PHP);
        $metadata = $this->createProvider([], argumentDefinitions: $definitions)->getComponentMetadataForVariantIdentifier('test:Card:Default');

        self::assertNotNull($metadata);
        self::assertNull($metadata->missingTransformerError);
        self::assertSame([], $metadata->errors);
        self::assertNotNull($metadata->fixture?->selectedVariant);
        self::assertSame(['payload.value'], array_column($metadata->fixture->selectedVariant->values, 'fixtureName'));
    }

    public function testUnrelatedTransformerErrorDoesNotBecomeMissingTransformerError(): void
    {
        file_put_contents(dirname($this->templatePath) . '/Card.transformer.php', '<?php return null;');
        $metadata = $this->createProvider([])->getComponentMetadataForVariantIdentifier('test:Card:Default');

        self::assertNotNull($metadata);
        self::assertNull($metadata->missingTransformerError);
        self::assertCount(1, $metadata->errors);
        self::assertStringContainsString('did not return an instance', $metadata->errors[0]);
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
     * @param array<string, ArgumentDefinition> $argumentDefinitions
     */
    private function createProvider(
        array $slotNames,
        bool $mapTemplatePath = true,
        ?ViewHelperResolverDelegateInterface $resolverDelegate = null,
        array $argumentDefinitions = [],
    ): ComponentMetadataProvider {
        $templatePaths = new TemplatePaths();
        $templatePaths->setTemplatePathAndFilename($this->templatePath);

        $resolverDelegate ??= new readonly class ($templatePaths, $slotNames, $argumentDefinitions) implements
            ComponentDefinitionProviderInterface,
            ComponentListProviderInterface,
            ComponentTemplateResolverInterface,
            ViewHelperResolverDelegateInterface
        {
            /**
             * @param list<string> $slotNames
             * @param array<string, ArgumentDefinition> $argumentDefinitions
             */
            public function __construct(private TemplatePaths $templatePaths, private array $slotNames, private array $argumentDefinitions)
            {
            }

            public function getComponentDefinition(string $viewHelperName): ComponentDefinition
            {
                return new ComponentDefinition('Card', $this->argumentDefinitions, false, $this->slotNames);
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
