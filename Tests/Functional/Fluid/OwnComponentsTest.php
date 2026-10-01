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
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Backend\Http\Application as BackendApplication;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Page\Event\ResolveVirtualJavaScriptImportEvent;
use TYPO3\CMS\Core\Page\ImportMapFactory;
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
        self::assertSame(
            ['@andersundsehr/frontend-studio/backend/variant-view.js'],
            $this->get(AssetCollector::class)->getJavaScriptModules(),
        );
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
        $html = $this->renderPreview('frontend.studio:variant.valueField:bool');

        self::assertStringContainsString('type="checkbox"', $html);
        self::assertSame(1, substr_count($html, 'backend.css'));
        self::assertSame(1, substr_count($html, 'variant-view.css'));
        self::assertLessThan(strpos($html, 'variant-view.css'), strpos($html, 'backend.css'));
    }

    #[DataProvider('ownComponentVariants')]
    public function testOwnVariantPreviewRegistersModuleAndDependenciesWithoutBackendSession(string $variantIdentifier): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        $html = $this->renderPreview($variantIdentifier);
        $imports = $this->getPreviewImports($html);

        $moduleIdentifiers = [
            '@andersundsehr/frontend-studio/backend/variant-view.js',
            '@typo3/core/ajax/ajax-request.js',
            '@typo3/backend/notification.js',
            '@typo3/backend/storage/persistent.js',
            'lit',
            '~labels/',
        ];
        foreach ($moduleIdentifiers as $identifier) {
            self::assertArrayHasKey($identifier, $imports);
        }

        self::assertSame(1, preg_match_all('/<script[^>]+src="[^"]*variant-view\.js[^"]*"[^>]*>/', $html, $scripts));
        self::assertStringContainsString('type="module"', $scripts[0][0]);
        self::assertLessThan(strpos($html, $scripts[0][0]), strpos($html, 'type="importmap"'));
        self::assertStringStartsWith('/typo3/language/domain/en/', $imports['~labels/']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ownComponentVariants(): iterable
    {
        yield 'controls' => ['frontend.studio:variant.controls:Default'];
        yield 'header' => ['frontend.studio:variant.header:Default'];
        yield 'sidebar' => ['frontend.studio:variant.sidebar:Default'];
        yield 'value field' => ['frontend.studio:variant.valueField:bool'];
    }

    public function testModuleUrlsRespectTheInstallationSubdirectory(): void
    {
        $html = $this->renderPreview('frontend.studio:variant.valueField:bool', '/subdirectory/');
        $imports = $this->getPreviewImports($html);

        $moduleIdentifiers = [
            '@andersundsehr/frontend-studio/backend/variant-view.js',
            '@typo3/core/ajax/ajax-request.js',
            '@typo3/backend/notification.js',
            'lit',
            '~labels/',
        ];
        foreach ($moduleIdentifiers as $identifier) {
            self::assertStringStartsWith('/subdirectory/', $imports[$identifier]);
        }

        self::assertStringContainsString('src="' . htmlspecialchars($imports['@andersundsehr/frontend-studio/backend/variant-view.js'], ENT_QUOTES) . '"', $html);
        self::assertStringStartsWith('/subdirectory/typo3/language/domain/', $imports['~labels/']);
    }

    public function testLabelModuleUrlsRespectTheBackendEntryPoint(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['entryPoint'] = '/admin';

        $imports = $this->getPreviewImports($this->renderPreview('frontend.studio:variant.valueField:bool'));

        self::assertStringStartsWith('/admin/language/domain/en/', $imports['~labels/']);
    }

    public function testLabelModuleRequestsRedirectWithoutBackendSession(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        $imports = $this->getPreviewImports($this->renderPreview('frontend.studio:variant.valueField:bool'));

        $response = $this->requestLabelModule($imports['~labels/'] . 'core.core');

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/typo3/login', $response->getHeaderLine('Location'));
        self::assertStringNotContainsString('javascript', $response->getHeaderLine('Content-Type'));
    }

    public function testLabelModuleRequestsReturnJavaScriptWithBackendSession(): void
    {
        $imports = $this->getPreviewImports($this->renderPreview('frontend.studio:variant.valueField:bool'));
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/BackendUser.csv');
        $backendUser = $this->setUpBackendUser(1);

        $response = $this->requestLabelModule($imports['~labels/'] . 'core.core', [
            BackendUserAuthentication::getCookieName() => $backendUser->getSession()->getJwt(),
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/javascript', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('export default new LabelProvider(', (string)$response->getBody());
        self::assertStringNotContainsString('throw new Error', (string)$response->getBody());
    }

    public function testModuleRenderingPreservesTheExistingLanguageService(): void
    {
        $languageService = $this->get(LanguageServiceFactory::class)->create('de');
        $GLOBALS['LANG'] = $languageService;

        $imports = $this->getPreviewImports($this->renderPreview('frontend.studio:variant.valueField:bool'));

        self::assertSame($languageService, $GLOBALS['LANG']);
        self::assertStringContainsString('/typo3/language/domain/de/', $imports['~labels/']);
    }

    public function testLabelImportListenerIsRegisteredBeforeRenderingAssets(): void
    {
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('en');
        $GLOBALS['TYPO3_REQUEST'] = new ServerRequest('https://preview.test/')
            ->withAttribute(ComponentPreviewContextMiddleware::PREVIEW_ATTRIBUTE, true)
            ->withAttribute('normalizedParams', new NormalizedParams(
                ['HTTP_HOST' => 'preview.test', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/'],
                $GLOBALS['TYPO3_CONF_VARS']['SYS'],
                Environment::getPublicPath() . '/index.php',
                Environment::getPublicPath(),
            ));
        $event = new ResolveVirtualJavaScriptImportEvent('labels/', $this->get(ImportMapFactory::class)->create());

        $this->get(EventDispatcherInterface::class)->dispatch($event);

        self::assertStringStartsWith('/typo3/language/domain/en/', $event->resolution ?? '');
    }

    #[DataProvider('nonPreviewRequests')]
    public function testLabelImportListenerIgnoresNonPreviewRequests(?int $applicationType, bool|string|null $previewAttribute): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        if ($applicationType !== null) {
            $GLOBALS['TYPO3_REQUEST'] = new ServerRequest('https://preview.test/')
                ->withAttribute('applicationType', $applicationType);
            if ($previewAttribute !== null) {
                $GLOBALS['TYPO3_REQUEST'] = $GLOBALS['TYPO3_REQUEST']->withAttribute(ComponentPreviewContextMiddleware::PREVIEW_ATTRIBUTE, $previewAttribute);
            }
        }

        $event = new ResolveVirtualJavaScriptImportEvent('labels/', $this->get(ImportMapFactory::class)->create());

        $this->get(EventDispatcherInterface::class)->dispatch($event);

        self::assertNull($event->resolution);
    }

    /**
     * @return iterable<string, array{?int, bool|string|null}>
     */
    public static function nonPreviewRequests(): iterable
    {
        yield 'no request' => [null, null];
        yield 'frontend request' => [SystemEnvironmentBuilder::REQUESTTYPE_FE, null];
        yield 'backend request' => [SystemEnvironmentBuilder::REQUESTTYPE_BE, null];
        yield 'disabled preview' => [SystemEnvironmentBuilder::REQUESTTYPE_FE, false];
        yield 'truthy preview marker' => [SystemEnvironmentBuilder::REQUESTTYPE_FE, '1'];
    }

    public function testPreviewWithoutModulesKeepsOrdinaryAssets(): void
    {
        $this->get(AssetCollector::class)->addInlineJavaScript('preview-test', 'window.previewTest = true;');

        $html = $this->renderPreview('site:card:Default');

        self::assertStringContainsString('window.previewTest = true;', $html);
        self::assertStringNotContainsString('type="importmap"', $html);
        self::assertStringNotContainsString('type="module"', $html);
    }

    public function testOwnVariantFragmentDoesNotIncludeModuleAssets(): void
    {
        $html = $this->renderPreview('frontend.studio:variant.valueField:bool', '/', 'fragment');

        self::assertStringContainsString('type="checkbox"', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    /**
     * @param array<string, string> $cookies
     */
    private function requestLabelModule(string $path, array $cookies = []): ResponseInterface
    {
        $request = new ServerRequest('https://preview.test' . $path, 'GET', null, [], [
            'HTTP_HOST' => 'preview.test',
            'HTTP_USER_AGENT' => 'TYPO3 Functional Test Request',
            'HTTPS' => 'on',
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => Environment::getPublicPath() . '/index.php',
            'REQUEST_URI' => $path,
            'REQUEST_METHOD' => 'GET',
        ])->withCookieParams($cookies);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $this->get(BackendApplication::class)->handle($request);
    }

    private function renderPreview(string $variantIdentifier, string $installationPath = '/', ?string $format = null): string
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['frontend_studio']['showOwnComponents'] = '1';
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('preview');
        $queryParams = ['componentVariant' => $variantIdentifier, 'previewTest' => 'yes'];
        if ($format !== null) {
            $queryParams['frontendStudioPreviewFormat'] = $format;
        }

        $requestPath = $installationPath . '__frontendStudio/preview?' . http_build_query($queryParams);
        $request = new ServerRequest('https://preview.test' . $requestPath)
            ->withQueryParams($queryParams)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
            ->withAttribute(ComponentPreviewContextMiddleware::PREVIEW_ATTRIBUTE, true)
            ->withAttribute('site', $site)
            ->withAttribute('language', $site->getDefaultLanguage())
            ->withAttribute('normalizedParams', new NormalizedParams(
                ['HTTP_HOST' => 'preview.test', 'HTTPS' => 'on', 'SCRIPT_NAME' => $installationPath . 'index.php', 'REQUEST_URI' => $requestPath],
                $GLOBALS['TYPO3_CONF_VARS']['SYS'],
                Environment::getPublicPath() . '/index.php',
                Environment::getPublicPath(),
            ));

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
        return $html;
    }

    /**
     * @return array<string, string>
     */
    private function getPreviewImports(string $html): array
    {
        if (preg_match('/<script type="importmap">(.*?)<\/script>/s', $html, $matches) !== 1) {
            self::fail('The preview does not include an import map.');
        }

        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR)['imports'];
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
