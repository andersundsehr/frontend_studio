<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Functional\Fluid;

use Psr\Http\Message\ServerRequestInterface;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantValues;
use Andersundsehr\FrontendStudio\Dto\ComponentMetadata;
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
use TYPO3\CMS\Frontend\Http\Application as FrontendApplication;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Frontend\Middleware\BackendUserAuthenticator;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\CookieScope;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Page\Event\ResolveVirtualJavaScriptImportEvent;
use TYPO3\CMS\Core\Page\ImportMapFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3Fluid\Fluid\View\TemplateView;
use Andersundsehr\FrontendStudio\Transformer\TypeTransformers;
use stdClass;

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
        self::assertStringContainsString('data-sidebar-height=""', $html);
        self::assertStringNotContainsString('--frontend-studio-variant-sidebar-height:', $html);
    }

    public function testRendersSavedSidebarHeightSeparatelyFromWidth(): void
    {
        $html = $this->renderVariantView(['variantSidebarWidth' => 420, 'variantSidebarHeight' => 480]);

        self::assertStringContainsString('--frontend-studio-variant-sidebar-width: 420px', $html);
        self::assertStringContainsString('data-sidebar-height="480"', $html);
        self::assertStringContainsString('--frontend-studio-variant-sidebar-height: 480px;', $html);
    }

    public function testRendersZeroSidebarHeightBeforeJavaScriptInitializes(): void
    {
        $html = $this->renderVariantView(['variantSidebarHeight' => 0]);

        self::assertStringContainsString('data-sidebar-height="0"', $html);
        self::assertStringContainsString('--frontend-studio-variant-sidebar-height: 0px;', $html);
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
            [
                '@andersundsehr/frontend-studio/backend/variant-view.js',
                '@andersundsehr/frontend-studio/backend/variant-header.js',
                '@andersundsehr/frontend-studio/backend/variant-sidebar.js',
                '@andersundsehr/frontend-studio/backend/variant-controls.js',
            ],
            $this->get(AssetCollector::class)->getJavaScriptModules(),
        );
    }

    public function testOwnComponentsAreHiddenFromTheTreeByDefault(): void
    {
        self::assertSame([], $this->getOwnComponentIdentifiers());
        self::assertSame([], $this->getOwnVariantIdentifiers());
    }

    public function testMissingTransformerHidesControlsUntilATypeTransformerIsRegistered(): void
    {
        $identifier = 'site:missingTransformer:Default';
        $provider = $this->get(ComponentMetadataProvider::class);
        $metadata = $provider->getComponentMetadataForVariantIdentifier($identifier);
        self::assertNotNull($metadata);
        self::assertNotNull($metadata->missingTransformerError);

        $assignments = [
            'selectedVariantIdentifier' => $identifier,
            'selectedComponentMetadata' => $metadata,
            'componentPreviewUri' => '/__frontendStudio/preview?componentVariant=' . rawurlencode($identifier),
        ];
        $html = $this->renderVariantView($assignments);
        self::assertStringContainsString('data-frontend-studio-missing-transformer', $html);
        self::assertStringContainsString('ArgumentTransformers', $html);
        self::assertStringContainsString('data-frontend-studio-create-transformer', $html);
        self::assertStringContainsString('Review the generated file', $html);
        self::assertStringContainsString('#[TypeTransformer]', $html);
        self::assertStringContainsString('MissingTransformer.transformer.php', $html);
        self::assertStringContainsString('stdClass', $html);
        foreach (['value', 'slot', 'reset', 'save', 'copy'] as $action) {
            self::assertDoesNotMatchRegularExpression('/data-frontend-studio-variant-' . $action . '(?:\\s|=|>)/', $html);
        }

        self::assertStringContainsString('data-frontend-studio-variant-tab-panel="html"', $html);
        self::assertStringContainsString('data-frontend-studio-variant-tab-panel="template"', $html);

        $this->get(TypeTransformers::class)->addTransformer(new class {
            public function transform(string $value = ''): stdClass
            {
                return (object)['value' => $value];
            }
        }, 'transform', stdClass::class, 0);
        $metadata = $provider->getComponentMetadataForVariantIdentifier($identifier);
        self::assertNotNull($metadata);
        self::assertNull($metadata->missingTransformerError);
        $html = $this->renderVariantView(array_replace($assignments, ['selectedComponentMetadata' => $metadata]));
        self::assertStringNotContainsString('data-frontend-studio-missing-transformer', $html);
        self::assertStringContainsString('data-frontend-studio-variant-value', $html);
        self::assertStringContainsString('data-frontend-studio-variant-slot', $html);
        self::assertStringContainsString('data-frontend-studio-variant-save', $html);
        self::assertStringContainsString('data-frontend-studio-variant-copy', $html);
    }

    public function testMissingTransformerMessageIsHtmlEscaped(): void
    {
        $metadata = $this->get(ComponentMetadataProvider::class)->getComponentMetadataForVariantIdentifier('site:card:Default');
        self::assertNotNull($metadata);
        $metadata = new ComponentMetadata(...array_replace(get_object_vars($metadata), [
            'missingTransformerError' => '<script>alert("unsafe")</script>',
        ]));
        $html = $this->renderVariantView([
            'selectedVariantIdentifier' => 'site:card:Default',
            'selectedComponentMetadata' => $metadata,
        ]);

        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>alert', $html);
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

    /** @param list<string> $modules */
    #[DataProvider('ownComponentVariants')]
    public function testOwnVariantPreviewLoadsOnlyItsComponentEntries(string $variantIdentifier, array $modules): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        $html = $this->renderPreview($variantIdentifier);
        self::assertSame($modules, $this->get(AssetCollector::class)->getJavaScriptModules());
        self::assertDoesNotMatchRegularExpression('/<script[^>]+src="[^"]*component-tree-startup\\.js/', $html);
        if ($modules === []) {
            self::assertStringNotContainsString('type="module"', $html);
            self::assertStringNotContainsString('type="importmap"', $html);
            return;
        }

        $imports = $this->getPreviewImports($html);
        foreach ($modules as $identifier) {
            self::assertArrayHasKey($identifier, $imports);
            self::assertStringContainsString('src="' . htmlspecialchars($imports[$identifier], ENT_QUOTES) . '"', $html);
        }

        self::assertStringNotContainsString('src="' . ($imports['@andersundsehr/frontend-studio/backend/variant-view.js'] ?? 'variant-view.js') . '"', $html);
        self::assertStringStartsWith('/typo3/language/domain/en/', $imports['~labels/']);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function ownComponentVariants(): iterable
    {
        $prefix = '@andersundsehr/frontend-studio/backend/';
        yield 'controls' => ['frontend.studio:variant.controls:Default', [$prefix . 'variant-controls.js']];
        yield 'header' => ['frontend.studio:variant.header:Default', [$prefix . 'variant-header.js']];
        yield 'sidebar' => ['frontend.studio:variant.sidebar:Default', [$prefix . 'variant-sidebar.js', $prefix . 'variant-controls.js']];
        yield 'value field' => ['frontend.studio:variant.valueField:bool', []];
    }

    public function testIsolatedControlsProvideAuthenticatedWriteEndpoints(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['frontend_studio']['showOwnComponents'] = '1';
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/BackendUser.csv');
        $backendUser = $this->setUpBackendUser(1);
        $cookie = $backendUser->getSession()->getJwt(new CookieScope('preview.test', true, '/'));
        $response = $this->requestPreview('frontend.studio:variant.controls:Default', backendCookie: $cookie);
        $html = (string)$response->getBody();
        self::assertSame(200, $response->getStatusCode(), $html);
        self::assertStringNotContainsString('TYPO3.settings.ajaxUrls', $html);

        foreach (['save', 'copy', 'create-transformer'] as $action) {
            if (preg_match('/data-' . $action . '-uri="([^"]+)"/', $html, $matches) !== 1) {
                self::fail('The isolated controls do not provide the ' . $action . ' endpoint.');
            }

            $uri = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            self::assertStringStartsWith('/typo3/ajax/frontend-studio/', $uri);
            parse_str((string)parse_url($uri, PHP_URL_QUERY), $query);
            self::assertNotEmpty($query['token'] ?? null);
            $request = new ServerRequest('https://preview.test' . $uri, 'POST', null, [], [
                'HTTP_HOST' => 'preview.test', 'HTTPS' => 'on', 'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_USER_AGENT' => 'TYPO3 Functional Test Request', 'SCRIPT_NAME' => '/index.php',
                'SCRIPT_FILENAME' => Environment::getPublicPath() . '/index.php',
                'REQUEST_URI' => $uri, 'REQUEST_METHOD' => 'POST',
            ])->withQueryParams($query)->withParsedBody([])
                ->withCookieParams([BackendUserAuthentication::getCookieName() => $cookie]);
            $GLOBALS['TYPO3_REQUEST'] = $request;
            $writeResponse = $this->get(BackendApplication::class)->handle($request);

            // An empty payload reaches the controller, rather than login or CSRF rejection.
            self::assertSame(400, $writeResponse->getStatusCode(), (string)$writeResponse->getBody());
            self::assertFalse(json_decode((string)$writeResponse->getBody(), true, 512, JSON_THROW_ON_ERROR)['success']);
        }
    }

    public function testIsolatedControlsWriteEndpointsRespectTheInstallationSubdirectory(): void
    {
        $html = $this->renderPreview('frontend.studio:variant.controls:Default', '/subdirectory/');
        foreach (['save', 'copy', 'create-transformer'] as $action) {
            if (preg_match('/data-' . $action . '-uri="([^"]+)"/', $html, $matches) !== 1) {
                self::fail('The isolated controls do not provide the ' . $action . ' endpoint.');
            }

            self::assertStringStartsWith('/subdirectory/typo3/ajax/frontend-studio/', $matches[1]);
        }
    }

    public function testModuleUrlsRespectTheInstallationSubdirectory(): void
    {
        $html = $this->renderPreview('frontend.studio:variant.header:Default', '/subdirectory/');
        $imports = $this->getPreviewImports($html);

        $moduleIdentifiers = [
            '@andersundsehr/frontend-studio/backend/variant-header.js',
            '@typo3/core/ajax/ajax-request.js',
            '@typo3/backend/notification.js',
            'lit',
            '~labels/',
        ];
        foreach ($moduleIdentifiers as $identifier) {
            self::assertStringStartsWith('/subdirectory/', $imports[$identifier]);
        }

        self::assertStringContainsString('src="' . htmlspecialchars($imports['@andersundsehr/frontend-studio/backend/variant-header.js'], ENT_QUOTES) . '"', $html);
        self::assertStringStartsWith('/subdirectory/typo3/language/domain/', $imports['~labels/']);
    }

    public function testLabelModuleUrlsRespectTheBackendEntryPoint(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['entryPoint'] = '/admin';

        $imports = $this->getPreviewImports($this->renderPreview('frontend.studio:variant.header:Default'));

        self::assertStringStartsWith('/admin/language/domain/en/', $imports['~labels/']);
    }

    public function testLabelModuleRequestsRedirectWithoutBackendSession(): void
    {
        unset($GLOBALS['BE_USER'], $GLOBALS['LANG']);
        $imports = $this->getPreviewImports($this->renderPreview('frontend.studio:variant.header:Default'));

        $response = $this->requestLabelModule($imports['~labels/'] . 'core.core');

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/typo3/login', $response->getHeaderLine('Location'));
        self::assertStringNotContainsString('javascript', $response->getHeaderLine('Content-Type'));
    }

    public function testLabelModuleRequestsReturnJavaScriptWithBackendSession(): void
    {
        $imports = $this->getPreviewImports($this->renderPreview('frontend.studio:variant.header:Default'));
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

        $imports = $this->getPreviewImports($this->renderPreview('frontend.studio:variant.header:Default'));

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

    #[DataProvider('wrappedPreviewFormats')]
    public function testWrapperAppearsInEveryHtmlPreviewFormat(?string $format): void
    {
        $this->get(AssetCollector::class)->addInlineJavaScript('wrapper-test', 'window.wrapperTest = true;');
        $response = $this->requestPreview('site:wrappedCard:Default', format: $format);
        self::assertSame(200, $response->getStatusCode());
        $html = (string)$response->getBody();
        if ($format === 'highlighted-fragment') {
            $html = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        self::assertStringContainsString('<section class="wrapper-example"><article>Wrapped preview</article></section>', $html);
        if ($format === null) {
            self::assertStringContainsString('window.wrapperTest = true;', $html);
        }
    }

    /** @return iterable<string, array{?string}> */
    public static function wrappedPreviewFormats(): iterable
    {
        yield 'full' => [null];
        yield 'fragment' => ['fragment'];
        yield 'highlighted' => ['highlighted-fragment'];
    }

    public function testWrapperDoesNotBecomePartOfFluidUsage(): void
    {
        $response = $this->requestPreview('site:wrappedCard:Default', format: 'fluid-usage');
        self::assertSame(200, $response->getStatusCode());
        $snippets = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('wrappedCard', $snippets['tag']);
        self::assertStringNotContainsString('wrapper-example', $snippets['tag'] . $snippets['inline']);
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
     * @param array<string, string> $slots
     */
    #[DataProvider('usageSlotsDataProvider')]
    public function testFluidUsageResponseReflectsOverridesAndInlineAvailability(array $slots, bool $inlineAvailable): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/BackendUser.csv');
        $this->setUpBackendUser(1);
        $response = $this->requestPreview('site:card:Default', format: 'fluid-usage', overrides: [
            'componentVariantValues' => json_encode(['title' => 'Overridden title'], JSON_THROW_ON_ERROR),
            'componentVariantSlots' => json_encode($slots, JSON_THROW_ON_ERROR),
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));
        $snippets = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['tag', 'inline'], array_keys($snippets));
        self::assertSame($inlineAvailable, $snippets['inline'] !== '');
        self::assertStringContainsString('Overridden title', $snippets['tag']);
        foreach ($slots as $content) {
            self::assertStringContainsString($content, html_entity_decode(strip_tags($snippets['tag']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
    }

    /**
     * @param array<string, string> $slots
     */
    #[DataProvider('usageSlotsDataProvider')]
    public function testSidebarRendersSeparateCopyableExamples(array $slots, bool $inlineAvailable): void
    {
        $metadata = $this->get(ComponentMetadataProvider::class)->getComponentMetadataForVariantIdentifier('site:card:Default');
        $html = $this->renderVariantView([
            'selectedVariantIdentifier' => 'site:card:Default',
            'selectedComponentMetadata' => $metadata,
            'variantActiveTab' => 'usage',
            'fluidUsageSource' => $this->get(FluidUsageSnippetRenderer::class)->render($metadata, slotOverrides: $slots),
        ]);

        self::assertStringContainsString('Tag syntax', $html);
        self::assertStringContainsString('Inline syntax', $html);
        self::assertStringContainsString('data-frontend-studio-copy-fluid-usage="tag"', $html);
        self::assertStringContainsString('data-frontend-studio-copy-fluid-usage="inline"', $html);
        self::assertStringContainsString('data-frontend-studio-fluid-usage-code="tag"', $html);
        self::assertStringContainsString('data-frontend-studio-fluid-usage-code="inline"', $html);
        self::assertSame(
            !$inlineAvailable,
            preg_match('/<div[^>]*data-frontend-studio-fluid-usage-block="inline"[^>]*\bhidden\b/', $html) === 1,
        );
        if ($inlineAvailable) {
            self::assertSame(1, preg_match('/<code[^>]*data-frontend-studio-fluid-usage-code="inline"[^>]*>(.*?)<\/code>/s', $html, $matches));
            $inlineSource = $matches[1] ?? '';
            self::assertStringContainsString('<span class="frontend-studio-variant-html-source__tag">site:card</span>', $inlineSource);
            self::assertStringContainsString('<span class="frontend-studio-variant-html-source__attribute">title</span>', $inlineSource);
            self::assertSame(
                $this->get(FluidUsageSnippetRenderer::class)->buildInline($metadata, slotOverrides: $slots),
                html_entity_decode(strip_tags($inlineSource), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            );
        }
    }

    /**
     * @param array<string, string> $slots
     */
    #[DataProvider('usageSlotsDataProvider')]
    public function testGeneratedUsageExamplesRenderThroughFluid(array $slots, bool $inlineAvailable): void
    {
        $metadata = $this->get(ComponentMetadataProvider::class)->getComponentMetadataForVariantIdentifier('site:card:Default');
        $renderer = $this->get(FluidUsageSnippetRenderer::class);
        $values = ComponentVariantValues::fromSubmittedValues(['title' => 'Overridden title']);
        $sources = [$renderer->build($metadata, $values, $slots)];
        if ($inlineAvailable) {
            $sources[] = $renderer->buildInline($metadata, $values, $slots);
        }

        foreach ($sources as $source) {
            $context = $this->get(RenderingContextFactory::class)->create([], new ServerRequest('https://preview.test/'));
            $context->getTemplatePaths()->setTemplateSource($source);
            $output = (string)new TemplateView($context)->render();

            self::assertStringContainsString('<article>Overridden title', $output);
            foreach ($slots as $content) {
                self::assertStringContainsString($content, $output);
            }
        }
    }

    /**
     * @return iterable<string, array{array<string, string>, bool}>
     */
    public static function usageSlotsDataProvider(): iterable
    {
        yield 'no populated slots' => [['default' => '', 'footer' => ''], true];
        yield 'default only' => [['default' => '<strong>Content</strong>', 'footer' => ''], true];
        yield 'named only' => [['default' => '', 'footer' => '<small>Footer</small>'], false];
        yield 'default and named' => [['default' => '<strong>Content</strong>', 'footer' => '<small>Footer</small>'], false];
        yield 'quoted default' => [['default' => "<strong>It's \\ready</strong>"], true];
    }

    public function testUsagePreservesQuotedArgumentValuesWhenRendered(): void
    {
        $metadata = $this->get(ComponentMetadataProvider::class)->getComponentMetadataForVariantIdentifier('site:card:Default');
        $renderer = $this->get(FluidUsageSnippetRenderer::class);
        $title = 'A "quote" & \path';
        $values = ComponentVariantValues::fromSubmittedValues(['title' => $title]);
        foreach ([$renderer->build($metadata, $values), $renderer->buildInline($metadata, $values)] as $source) {
            $context = $this->get(RenderingContextFactory::class)->create([], new ServerRequest('https://preview.test/'));
            $context->getTemplatePaths()->setTemplateSource($source);
            $output = (string)new TemplateView($context)->render();

            self::assertSame('<article>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</article>', trim($output));
        }
    }

    #[DataProvider('usageNumericValuesDataProvider')]
    public function testUsagePreservesNumericArgumentValuesWhenRendered(int|float $value, string $expected): void
    {
        $metadata = $this->get(ComponentMetadataProvider::class)->getComponentMetadataForVariantIdentifier('site:card:Default');
        $renderer = $this->get(FluidUsageSnippetRenderer::class);
        $values = ComponentVariantValues::fromSubmittedValues(['title' => $value]);
        foreach ([$renderer->build($metadata, $values, []), $renderer->buildInline($metadata, $values, [])] as $source) {
            $context = $this->get(RenderingContextFactory::class)->create([], new ServerRequest('https://preview.test/'));
            $context->getTemplatePaths()->setTemplateSource($source);
            $output = (string)new TemplateView($context)->render();

            self::assertSame('<article>' . $expected . '</article>', trim($output));
        }
    }

    /**
     * @return iterable<string, array{int|float, string}>
     */
    public static function usageNumericValuesDataProvider(): iterable
    {
        yield 'zero integer' => [0, '0'];
        yield 'zero float' => [0.0, '0'];
        yield 'positive integer' => [12, '12'];
        yield 'integral float' => [12.0, '12'];
        yield 'positive decimal' => [1.5, '1.5'];
        yield 'negative integer' => [-1, '-1'];
        yield 'negative decimal' => [-1.5, '-1.5'];
        yield 'large positive float' => [1.0E+20, '1.0E+20'];
        yield 'large negative float' => [-1.0E+20, '-1.0E+20'];
        yield 'small positive float' => [1.0E-20, '1.0E-20'];
        yield 'small negative float' => [-1.0E-20, '-1.0E-20'];
        yield 'maximum integer' => [PHP_INT_MAX, (string)PHP_INT_MAX];
        yield 'minimum integer' => [PHP_INT_MIN, (string)PHP_INT_MIN];
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
        $response = $this->requestPreview($variantIdentifier, $installationPath, $format);
        $html = (string)$response->getBody();
        self::assertSame(200, $response->getStatusCode(), $html);

        return $html;
    }

    public function testProductionControlsKeepTemporaryPreviewEditingAvailable(): void
    {
        $metadata = $this->get(ComponentMetadataProvider::class)->getComponentMetadataForVariantIdentifier('site:card:Default');
        self::assertNotNull($metadata);
        $metadata = new ComponentMetadata(...array_replace(get_object_vars($metadata), ['readOnly' => true]));
        $html = $this->renderVariantView(['selectedVariantIdentifier' => 'site:card:Default', 'selectedComponentMetadata' => $metadata]);
        self::assertStringContainsString('Production: fixture files are read-only.', $html);
        self::assertStringNotContainsString('data-frontend-studio-variant-save', $html);
        self::assertStringNotContainsString('data-frontend-studio-variant-copy', $html);
        self::assertStringContainsString('data-frontend-studio-variant-reset', $html);
    }

    #[DataProvider('ajaxPreviewFormats')]
    public function testAjaxPreviewAuthenticatesTheSessionThroughTheCompleteMiddlewareStack(string $format): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/BackendUser.csv');
        $backendUser = $this->setUpBackendUser(1);
        $query = ['previewTest' => 'yes', 'componentVariant' => 'site:card:Default', 'componentVariantValues' => '{"title":"Authenticated AJAX"}', 'frontendStudioPreviewFormat' => $format];
        $path = '/__frontendStudio/preview?' . http_build_query($query);
        $request = new ServerRequest('https://preview.test' . $path, 'GET', null, [], [
            'HTTP_HOST' => 'preview.test', 'HTTPS' => 'on', 'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'TYPO3 Functional Test Request', 'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => Environment::getPublicPath() . '/index.php', 'REQUEST_URI' => $path, 'REQUEST_METHOD' => 'GET',
        ])->withQueryParams($query)->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->withCookieParams([BackendUserAuthentication::getCookieName() => $backendUser->getSession()->getJwt(new CookieScope('preview.test', true, '/'))]);
        $this->get(Context::class)->setAspect('backend.user', new UserAspect());
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $response = $this->get(FrontendApplication::class)->handle($request);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('Authenticated AJAX', html_entity_decode(strip_tags((string)$response->getBody()), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    #[DataProvider('ajaxPreviewFormats')]
    public function testPreviewWithoutInjectedContextUsesTheAuthenticatedSharedContext(string $format): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/BackendUser.csv');
        $backendUser = $this->setUpBackendUser(1);
        $cookie = $backendUser->getSession()->getJwt(new CookieScope('preview.test', true, '/'));
        $response = $this->requestPreview('site:card:Default', format: $format, overrides: [
            'componentVariantValues' => '{"title":"Shared context preview"}',
            'componentVariantSlots' => '{"default":"<strong>Shared slot</strong>"}',
        ], backendCookie: $cookie);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringContainsString('Shared context preview', html_entity_decode(strip_tags((string)$response->getBody()), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        self::assertStringContainsString('Shared slot', html_entity_decode(strip_tags((string)$response->getBody()), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** @return iterable<string, array{string}> */
    public static function ajaxPreviewFormats(): iterable
    {
        yield 'fragment' => ['fragment'];
        yield 'highlighted' => ['highlighted-fragment'];
        yield 'usage' => ['fluid-usage'];
    }

    public function testPreviewOverridesRequireARealBackendSession(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/BackendUser.csv');
        $backendUser = $this->setUpBackendUser(1);
        $cookie = $backendUser->getSession()->getJwt(new CookieScope('preview.test', true, '/'));
        $overrides = ['componentVariantValues' => '{"title":"Session preview"}'];
        $response = $this->requestPreview('site:card:Default', format: 'fragment', overrides: $overrides, backendCookie: $cookie);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Session preview', (string)$response->getBody());

        $backendUser->logoff();
        $response = $this->requestPreview('site:card:Default', format: 'fragment', overrides: $overrides, backendCookie: $cookie);
        self::assertSame(403, $response->getStatusCode());

        $response = $this->requestPreview('site:card:Default', format: 'fragment', overrides: $overrides, backendCookie: 'forged-session');
        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * @param array<string, string> $overrides
     */
    private function requestPreview(string $variantIdentifier, string $installationPath = '/', ?string $format = null, array $overrides = [], ?string $backendCookie = null): ResponseInterface
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['frontend_studio']['showOwnComponents'] = '1';
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('preview');
        $queryParams = array_replace(['componentVariant' => $variantIdentifier, 'previewTest' => 'yes'], $overrides);
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
        if ($backendCookie !== null) {
            $this->get(Context::class)->setAspect('backend.user', new UserAspect());
            $request = $request->withCookieParams([BackendUserAuthentication::getCookieName() => $backendCookie]);
            $handler = $this->createMock(RequestHandlerInterface::class);
            $handler->method('handle')->willReturnCallback(fn(ServerRequestInterface $authenticatedRequest): ResponseInterface => $middleware->process($authenticatedRequest, $this->createMock(RequestHandlerInterface::class)));
            return $this->get(BackendUserAuthenticator::class)->process($request, $handler);
        }

        return $middleware->process($request, $this->createMock(RequestHandlerInterface::class));
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
            'variantSidebarHeight' => null,
            'variantActiveTab' => 'values',
            'renderedHtmlSource' => '',
            'renderedHtmlStatus' => '',
            'fluidTemplateSource' => '',
            'fluidUsageSource' => ['tag' => '', 'inline' => ''],
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
