<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Functional\Fluid;

use Andersundsehr\FrontendStudio\Controller\FrontendStudioModuleController;
use Andersundsehr\FrontendStudio\Dto\ComponentMetadata;
use Andersundsehr\FrontendStudio\Service\ComponentMetadataProvider;
use Andersundsehr\FrontendStudio\Service\ComponentDocumentation;
use Andersundsehr\FrontendStudio\Service\ComponentPreviewRenderer;
use Andersundsehr\FrontendStudio\Service\FluidTemplateAnalyzer;
use Andersundsehr\FrontendStudio\Service\FluidUsageSnippetRenderer;
use Andersundsehr\FrontendStudio\Service\HtmlSourceHighlighter;
use Andersundsehr\FrontendStudio\Service\PreviewContextResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use TYPO3\CMS\Backend\Http\Application as BackendApplication;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\CookieScope;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3Fluid\Fluid\View\TemplateView;

final class ComponentOverviewTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor'];

    protected array $testExtensionsToLoad = [
        __DIR__ . '/../../..', __DIR__ . '/../Fixtures/Extensions/preview_site_set',
    ];

    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/frontend_studio/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    public function testFixtureOrderDescriptionUniqueFramesAndControls(): void
    {
        $metadata = $this->get(ComponentMetadataProvider::class)->getComponentMetadataForIdentifier('site:card');
        self::assertNotNull($metadata?->fixture?->absolutePath);
        $path = $metadata->fixture->absolutePath;
        $original = file_get_contents($path);
        $documentation = dirname($path) . '/Card.md';
        try {
            file_put_contents($path, "variants:\n  Zebra:\n    title: First\n  Alpha:\n    title: Second\n");
            file_put_contents($documentation, "# Documentation\n\n<script>alert(1)</script>");
            $assignments = $this->assignments('site:card');
            self::assertSame('site:card:Zebra', $assignments['selectedVariantIdentifier']);
            self::assertSame(['Zebra', 'Alpha'], array_column($assignments['overviewVariants'], 'name'));
            self::assertSame(file_get_contents($documentation), $assignments['documentationMarkdown']);
            $html = $this->render($assignments);
            self::assertSame(2, substr_count($html, 'data-overview-frame data-variant-identifier'));
            foreach (['site-select', 'language-select', 'variant-reset', 'variant-copy', 'variant-save'] as $control) {
                self::assertStringNotContainsString('data-frontend-studio-' . $control, $html);
            }

            self::assertStringContainsString('class="frontend-studio-markdown" data-overview-description-content', $html);
            self::assertStringContainsString('data-component-documentation', $html);
            self::assertStringContainsString('data-doc-editor-controls', $html);
            self::assertStringContainsString('data-doc-save', $html);
            self::assertStringContainsString('data-frontend-studio-variant-value', $html);
            self::assertSame(2, substr_count($html, 'data-overview-variant-link'));
            self::assertStringNotContainsString('Open Zebra variant', $html);
            self::assertStringNotContainsString('Open Alpha variant', $html);
            self::assertStringContainsString('componentVariant=site%3Acard%3AAlpha', $html);
            self::assertStringContainsString('site=preview', $html);
            self::assertStringContainsString('language=de', $html);
            self::assertStringContainsString('loading="lazy"', $html);
            self::assertStringContainsString('&lt;script&gt;', $html);
            self::assertStringNotContainsString('<script>alert(1)</script>', $html);
            preg_match_all('/\\bid="([^"]+)"/', $html, $matches);
            self::assertSame($matches[1], array_values(array_unique($matches[1])));
        } finally {
            file_put_contents($path, $original);
            unlink($documentation);
        }
    }

    public function testEmptySingleAndUnknownComponentsHaveClearStates(): void
    {
        $metadata = $this->get(ComponentMetadataProvider::class)->getComponentMetadataForIdentifier('site:card');
        self::assertNotNull($metadata?->fixture?->absolutePath);
        $path = $metadata->fixture->absolutePath;
        $original = file_get_contents($path);
        try {
            file_put_contents($path, "variants:\n  Default:\n    title: Single\n");
            $single = $this->assignments('site:card');
            self::assertCount(1, $single['overviewVariants']);
            file_put_contents($path, "variants: {}\n");
            $empty = $this->assignments('site:card');
            self::assertSame('', $empty['selectedVariantIdentifier']);
            self::assertSame([], $empty['overviewVariants']);
            self::assertStringContainsString('No fixture variants yet', $this->render($empty));
            self::assertStringContainsString('data-component-documentation', $this->render($empty));
            self::assertStringContainsString('data-doc-rich', $this->render($empty));
            self::assertStringContainsString('Component not found', $this->render($this->assignments('site:unknown')));
            self::assertSame("variants: {}\n", file_get_contents($path));
        } finally {
            file_put_contents($path, $original);
        }
    }

    public function testProductionOverviewRendersDocumentationWithoutEditingControls(): void
    {
        $assignments = $this->assignments('site:card');
        $component = $assignments['overviewComponent'];
        self::assertInstanceOf(ComponentMetadata::class, $component);
        $assignments['overviewComponent'] = new ComponentMetadata(...array_replace(get_object_vars($component), ['readOnly' => true]));
        $assignments['documentationMarkdown'] = '# Production documentation';
        $html = $this->render($assignments);
        self::assertStringContainsString('data-overview-markdown', $html);
        self::assertStringContainsString('# Production documentation', $html);
        self::assertStringNotContainsString('data-component-documentation', $html);
        self::assertStringNotContainsString('data-doc-save', $html);
        self::assertStringNotContainsString('data-doc-editor-controls', $html);
        $assignments['documentationMarkdown'] = '';
        self::assertStringContainsString('No documentation yet', $this->render($assignments));
    }

    public function testAuthenticatedModuleRoutesKeepOverviewAndVariantViewsDistinct(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/BackendUser.csv');
        $user = $this->setUpBackendUser(1);
        $cookie = $user->getSession()->getJwt(new CookieScope('preview.test', true, '/'));
        foreach (['component' => 'site:card', 'componentVariant' => 'site:card:Default'] as $parameter => $identifier) {
            $uri = (string)$this->get(UriBuilder::class)->buildUriFromRoute('admin_frontendstudio', [$parameter => $identifier]);
            parse_str((string)parse_url($uri, PHP_URL_QUERY), $query);
            $request = new ServerRequest('https://preview.test' . $uri, 'GET', null, [], [
                'HTTP_HOST' => 'preview.test', 'HTTPS' => 'on', 'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_USER_AGENT' => 'TYPO3 Functional Test Request', 'SCRIPT_NAME' => '/index.php',
                'SCRIPT_FILENAME' => Environment::getPublicPath() . '/index.php',
                'REQUEST_URI' => $uri, 'REQUEST_METHOD' => 'GET',
            ])->withQueryParams($query)->withCookieParams([BackendUserAuthentication::getCookieName() => $cookie]);
            $GLOBALS['TYPO3_REQUEST'] = $request;
            $response = $this->get(BackendApplication::class)->handle($request);
            $html = (string)$response->getBody();
            self::assertSame(200, $response->getStatusCode(), $html);
            if ($parameter === 'component') {
                self::assertStringContainsString('data-component-overview', $html);
                self::assertStringContainsString('/Css/Backend/component-markdown.css', $html);
                self::assertStringNotContainsString('data-frontend-studio-variant-sidebar', $html);
            } else {
                self::assertStringContainsString('data-frontend-studio-variant-sidebar', $html);
                self::assertStringNotContainsString('data-component-overview', $html);
            }
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function liveReloadContexts(): iterable
    {
        yield 'Development' => ['Development', true];
        yield 'Production' => ['Production', false];
    }

    #[DataProvider('liveReloadContexts')]
    public function testOverviewAndVariantExposeTheAssignedLiveReloadUri(string $context, bool $enabled): void
    {
        $property = new ReflectionProperty(Environment::class, 'context');
        $original = Environment::getContext();
        try {
            $property->setValue(null, new ApplicationContext($context));
            $expectedUri = $enabled
                ? (string)$this->get(UriBuilder::class)->buildUriFromRoute('ajax_frontend_studio_component_change_stream')
                : '';
            $variantAssignments = new ReflectionMethod(FrontendStudioModuleController::class, 'getVariantAssignments')->invoke(
                $this->get(FrontendStudioModuleController::class),
                'site:card:Default',
                new ServerRequest('https://example.test/typo3/module/admin/frontend-studio'),
                false,
            );
            foreach (['Overview' => $this->assignments('site:card'), 'Variant' => $variantAssignments] as $template => $assignments) {
                self::assertSame($expectedUri, $assignments['componentChangeStreamUri']);
                self::assertStringContainsString(
                    'data-component-change-stream-uri="' . htmlspecialchars($expectedUri) . '"',
                    $this->render($assignments, $template),
                    $template,
                );
            }
        } finally {
            $property->setValue(null, $original);
        }
    }

    /** @return iterable<string, array{int, bool}> */
    public static function navigationToggleVersions(): iterable
    {
        yield 'TYPO3 13' => [13, false];
        yield 'TYPO3 14' => [14, true];
    }

    #[DataProvider('navigationToggleVersions')]
    public function testOverviewOnlyLoadsNavigationToggleOnSupportedVersions(int $majorVersion, bool $supported): void
    {
        $assignments = $this->assignments('site:card', $majorVersion);
        self::assertSame($supported, $assignments['showContentNavigationToggle']);
        $html = $this->render($assignments);
        $modules = $this->get(AssetCollector::class)->getJavaScriptModules();
        $module = '@typo3/backend/viewport/content-navigation-toggle.js';
        if ($supported) {
            self::assertContains($module, $modules);
            self::assertStringContainsString('<typo3-backend-content-navigation-toggle', $html);
        } else {
            self::assertNotContains($module, $modules);
            self::assertStringNotContainsString('<typo3-backend-content-navigation-toggle', $html);
        }
    }

    /** @return array<string, mixed> */
    private function assignments(string $identifier, ?int $majorVersion = null): array
    {
        $controller = $this->get(FrontendStudioModuleController::class);
        if ($majorVersion !== null) {
            $version = $this->createStub(Typo3Version::class);
            $version->method('getMajorVersion')->willReturn($majorVersion);
            $controller = new FrontendStudioModuleController(
                $this->get(ModuleTemplateFactory::class),
                $this->get(ComponentMetadataProvider::class),
                $this->get(ComponentPreviewRenderer::class),
                $this->get(FluidUsageSnippetRenderer::class),
                $this->get(HtmlSourceHighlighter::class),
                $this->get(UriBuilder::class),
                $this->get(SiteFinder::class),
                $this->get(PreviewContextResolver::class),
                $version,
                $this->get(AssetCollector::class),
                $this->get(FluidTemplateAnalyzer::class),
                $this->get(ComponentDocumentation::class),
            );
        }

        $method = new ReflectionMethod(FrontendStudioModuleController::class, 'getOverviewAssignments');
        return $method->invoke(
            $controller,
            $identifier,
            new ServerRequest('https://example.test/typo3/module/admin/frontend-studio')->withQueryParams(['site' => 'preview', 'language' => 'de']),
        );
    }

    /** @param array<string, mixed> $assignments */
    private function render(array $assignments, string $template = 'Overview'): string
    {
        $context = $this->get(RenderingContextFactory::class)->create([
            'templateRootPaths' => [__DIR__ . '/../../../Resources/Private/Templates'],
        ], new ServerRequest('https://example.test/typo3/module/admin/frontend-studio'));
        $view = new TemplateView($context);
        $view->assignMultiple($assignments);
        return (string)$view->render('FrontendStudio/' . $template);
    }
}
