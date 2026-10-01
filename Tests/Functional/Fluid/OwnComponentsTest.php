<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Functional\Fluid;

use Andersundsehr\FrontendStudio\Middleware\ComponentPreviewContextMiddleware;
use Andersundsehr\FrontendStudio\Middleware\ComponentPreviewMiddleware;
use Andersundsehr\FrontendStudio\Service\ComponentMetadataProvider;
use Andersundsehr\FrontendStudio\Service\ComponentPathResolverInterface;
use Andersundsehr\FrontendStudio\Service\ComponentPreviewRendererInterface;
use Andersundsehr\FrontendStudio\Service\ComponentTreeDataProvider;
use Andersundsehr\FrontendStudio\Service\FluidUsageSnippetRenderer;
use Andersundsehr\FrontendStudio\Service\HtmlSourceHighlighter;
use Andersundsehr\FrontendStudio\Service\PreviewAssetRenderer;
use Andersundsehr\FrontendStudio\Service\PreviewTypoScriptContextBuilderInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3Fluid\Fluid\View\TemplateView;

final class OwnComponentsTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        __DIR__ . '/../../..',
        __DIR__ . '/../Fixtures/Extensions/preview_site_set',
    ];

    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/frontend_studio/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    public function testRendersTheEmptyVariantView(): void
    {
        $html = $this->renderVariantView([]);

        self::assertStringContainsString('data-frontend-studio-variant-view', $html);
        self::assertStringContainsString('No component variant selected', $html);
        self::assertStringContainsString('The rendered component preview is not available.', $html);
        self::assertStringNotContainsString('data-frontend-studio-variant-sidebar-resize', $html);
    }

    public function testRendersSelectedVariantControlsAndPreview(): void
    {
        $metadata = $this->get(ComponentMetadataProvider::class)->getComponentMetadataForVariantIdentifier('site:card:Default');
        self::assertNotNull($metadata);
        self::assertNotNull($metadata->fixture?->selectedVariant);

        $html = $this->renderVariantView([
            'selectedVariantIdentifier' => 'site:card:Default',
            'selectedComponentMetadata' => $metadata,
            'componentPreviewUri' => '/__frontendStudio/preview?componentVariant=site%3Acard%3ADefault',
            'previewSites' => [['identifier' => 'preview', 'title' => 'Preview', 'languagesJson' => '[]']],
            'previewLanguages' => [['value' => 'en-US', 'title' => 'English']],
            'selectedSiteIdentifier' => 'preview',
            'selectedLanguageHreflang' => 'en-US',
        ]);

        self::assertStringContainsString('data-frontend-studio-site-select', $html);
        self::assertStringContainsString('data-frontend-studio-language-select', $html);
        self::assertMatchesRegularExpression('/<option value="preview"[^>]*\\bselected\\b/', $html);
        self::assertMatchesRegularExpression('/<option value="en-US"[^>]*\\bselected\\b/', $html);
        self::assertStringContainsString('data-frontend-studio-variant-frame', $html);
        self::assertStringContainsString('data-frontend-studio-variant-sidebar-resize', $html);
        self::assertStringContainsString('data-frontend-studio-variant-tab-panel="values"', $html);
        self::assertStringContainsString('data-frontend-studio-variant-save', $html);
        self::assertStringContainsString('data-fixture-name="title"', $html);
        self::assertStringContainsString('data-frontend-studio-fluid-usage-code', $html);
    }

    public function testOwnComponentsAreHiddenFromTheTreeByDefault(): void
    {
        self::assertSame([], $this->getOwnComponentIdentifiers());
        self::assertSame([], $this->getOwnVariantIdentifiers());
    }

    public function testExtensionSettingShowsOwnComponentsInTheTree(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['frontend_studio']['showOwnComponents'] = '1';

        self::assertSame([
            'frontend.studio:variant.controls',
            'frontend.studio:variant.header',
            'frontend.studio:variant.sidebar',
            'frontend.studio:variant.valueField',
        ], $this->getOwnComponentIdentifiers());
        self::assertSame([
            'frontend.studio:variant.controls:Default',
            'frontend.studio:variant.header:Default',
            'frontend.studio:variant.header:Empty',
            'frontend.studio:variant.sidebar:Default',
            'frontend.studio:variant.valueField:string',
            'frontend.studio:variant.valueField:bool',
            'frontend.studio:variant.valueField:boolean',
            'frontend.studio:variant.valueField:int',
            'frontend.studio:variant.valueField:integer',
            'frontend.studio:variant.valueField:float',
            'frontend.studio:variant.valueField:double',
            'frontend.studio:variant.valueField:Multiline',
            'frontend.studio:variant.valueField:DateTime',
            'frontend.studio:variant.valueField:Select',
            'frontend.studio:variant.valueField:Null',
            'frontend.studio:variant.valueField:Fallback',
        ], $this->getOwnVariantIdentifiers());
    }

    public function testOwnFixtureVariantsRender(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['frontend_studio']['showOwnComponents'] = '1';

        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('preview');
        $request = $this->get(PreviewTypoScriptContextBuilderInterface::class)->build(
            new ServerRequest('https://preview.test/__frontendStudio/preview?previewTest=yes')
                ->withQueryParams(['previewTest' => 'yes'])
                ->withAttribute('site', $site)
                ->withAttribute('language', $site->getDefaultLanguage()),
        );
        $renderer = $this->get(ComponentPreviewRendererInterface::class);

        $expectedMarkupsByVariant = [
            'frontend.studio:variant.controls:Default' => ['data-frontend-studio-variant-save'],
            'frontend.studio:variant.header:Default' => ['data-frontend-studio-site-select'],
            'frontend.studio:variant.header:Empty' => ['No component variant selected'],
            'frontend.studio:variant.sidebar:Default' => ['data-frontend-studio-variant-sidebar-resize'],
            'frontend.studio:variant.valueField:string' => ['type="text"', 'data-fixture-name="title"'],
            'frontend.studio:variant.valueField:bool' => ['type="checkbox"', 'data-fixture-type="bool"'],
            'frontend.studio:variant.valueField:boolean' => ['type="checkbox"', 'data-fixture-type="boolean"'],
            'frontend.studio:variant.valueField:int' => ['type="number"', 'step="1"', 'data-fixture-type="int"'],
            'frontend.studio:variant.valueField:integer' => ['type="number"', 'step="1"', 'data-fixture-type="integer"', 'data-fixture-value-null="false"', 'value="0"'],
            'frontend.studio:variant.valueField:float' => ['type="number"', 'step="any"', 'data-fixture-type="float"'],
            'frontend.studio:variant.valueField:double' => ['type="number"', 'step="any"', 'data-fixture-type="double"'],
            'frontend.studio:variant.valueField:Multiline' => ['<textarea', 'data-fixture-type="string"', 'First line'],
            'frontend.studio:variant.valueField:DateTime' => ['type="datetime-local"', 'value="2026-09-30T10:30"'],
            'frontend.studio:variant.valueField:Select' => ['<select', 'class="form-select"', 'value="second" selected', 'Second option'],
            'frontend.studio:variant.valueField:Null' => ['type="text"', 'data-fixture-type="null"', 'data-fixture-value-null="true"'],
            'frontend.studio:variant.valueField:Fallback' => ['<textarea', 'data-fixture-type="array"'],
        ];

        foreach ($expectedMarkupsByVariant as $variantIdentifier => $expectedMarkup) {
            $html = $renderer->renderVariant($variantIdentifier, $request);
            foreach ($expectedMarkup as $markup) {
                self::assertStringContainsString($markup, $html, $variantIdentifier);
            }
        }
    }

    public function testOwnVariantPreviewLoadsFixtureAndComponentStylesheets(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['frontend_studio']['showOwnComponents'] = '1';
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('preview');
        $variantIdentifier = 'frontend.studio:variant.valueField:bool';
        $request = new ServerRequest('https://preview.test/__frontendStudio/preview?componentVariant=' . rawurlencode($variantIdentifier) . '&previewTest=yes')
            ->withQueryParams(['componentVariant' => $variantIdentifier, 'previewTest' => 'yes'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
            ->withAttribute(ComponentPreviewContextMiddleware::PREVIEW_ATTRIBUTE, true)
            ->withAttribute('site', $site)
            ->withAttribute('language', $site->getDefaultLanguage());
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        $middleware = new ComponentPreviewMiddleware(
            $this->get(ComponentPreviewRendererInterface::class),
            $this->get(ComponentMetadataProvider::class),
            $this->get(ComponentPathResolverInterface::class),
            $this->get(FluidUsageSnippetRenderer::class),
            $this->get(HtmlSourceHighlighter::class),
            $this->get(PreviewAssetRenderer::class),
            $this->get(ListenerProvider::class),
            $this->get(PreviewTypoScriptContextBuilderInterface::class),
        );

        $response = $middleware->process($request, $this->createMock(RequestHandlerInterface::class));
        $html = (string)$response->getBody();

        self::assertSame(200, $response->getStatusCode(), $html);
        self::assertStringContainsString('type="checkbox"', $html);
        self::assertSame(1, substr_count($html, 'backend.css'));
        self::assertSame(1, substr_count($html, 'variant-view.css'));
        self::assertLessThan(strpos($html, 'variant-view.css'), strpos($html, 'backend.css'));
    }

    /**
     * @param array<string, mixed> $assignments
     */
    private function renderVariantView(array $assignments): string
    {
        $renderingContext = $this->get(RenderingContextFactory::class)->create([
            'templateRootPaths' => [__DIR__ . '/../../../Resources/Private/Templates'],
        ], new ServerRequest('https://example.test/typo3/module/admin/frontend-studio'));
        $view = new TemplateView($renderingContext);
        $view->assignMultiple(array_replace([
            'selectedVariantIdentifier' => '',
            'selectedComponentMetadata' => null,
            'componentPreviewUri' => null,
            'componentChangeStreamUri' => '',
            'previewSites' => [],
            'previewLanguages' => [],
            'selectedSiteIdentifier' => '',
            'selectedLanguageHreflang' => '',
            'variantSidebarWidth' => 360,
            'variantActiveTab' => 'values',
            'renderedHtmlSource' => '',
            'renderedHtmlStatus' => '',
            'fluidTemplateSource' => '',
            'fluidUsageSource' => '',
        ], $assignments));

        return (string)$view->render('FrontendStudio/Variant');
    }

    /**
     * @return list<string>
     */
    private function getOwnComponentIdentifiers(): array
    {
        $nodes = $this->get(ComponentTreeDataProvider::class)->getTreeNodes();
        return array_values(array_map(
            static fn(array $node): string => $node['identifier'],
            array_filter($nodes, static fn(array $node): bool => $node['nodeType'] === 'component'
                && str_starts_with($node['identifier'], 'frontend.studio:')),
        ));
    }

    /**
     * @return list<string>
     */
    private function getOwnVariantIdentifiers(): array
    {
        $nodes = $this->get(ComponentTreeDataProvider::class)->getTreeNodes();
        return array_values(array_map(
            static fn(array $node): string => $node['identifier'],
            array_filter($nodes, static fn(array $node): bool => $node['nodeType'] === 'variant'
                && str_starts_with($node['identifier'], 'frontend.studio:')),
        ));
    }
}
