<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Functional\Service;

use Andersundsehr\FrontendStudio\Controller\FrontendStudioModuleController;
use Andersundsehr\FrontendStudio\Dto\ComponentTemplateMetadata;
use Andersundsehr\FrontendStudio\Service\FluidTemplateAnalyzer;
use Andersundsehr\FrontendStudio\Service\ComponentMetadataProvider;
use TYPO3Fluid\Fluid\View\TemplateView;
use Andersundsehr\FrontendStudio\Service\HtmlSourceHighlighter;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Backend\Http\RouteDispatcher;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\Exception\InvalidRequestTokenException;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class FluidTemplateAnalyzerTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor'];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/frontend_studio',
        'typo3conf/ext/frontend_studio/Tests/Functional/Fixtures/Extensions/preview_site_set',
    ];

    public function testInstalledValidatorUsesSnapshotAndTemplateLinesWithoutRendering(): void
    {
        $analyzer = new FluidTemplateAnalyzer($this->get(RenderingContextFactory::class), new HtmlSourceHighlighter());
        foreach (['/selected.html', '/selected.fluid.html'] as $path) {
            $template = new ComponentTemplateMetadata('Selected', $path, null, null, [], "<p>Valid</p>\n<f:for as=\"item\" />", null);
            $result = $analyzer->analyze($template);
            self::assertSame('', $result['status']);
            self::assertSame(1, $result['errorCount']);
            self::assertStringContainsString('is-error" data-line="2"', $result['source']);
            self::assertStringContainsString('frontend-studio-template-marker" data-character="2"', $result['source']);
            self::assertStringContainsString('Error: Required argument &quot;each&quot; was not supplied. (error code 1237823699).', $result['source']);
            self::assertStringNotContainsString($path, $result['source']);
            self::assertStringNotContainsString('Fluid parse error in template', $result['source']);
            self::assertStringNotContainsString('Template source chunk:', $result['source']);
        }

        // Rendering this throws without a configured link target; parsing must succeed.
        $template = new ComponentTemplateMetadata('Selected', '/selected.html', null, null, [], '<f:link.page pageUid="123">Link</f:link.page>', null);
        self::assertSame(0, $analyzer->analyze($template)['errorCount']);
        self::assertSame(1, $analyzer->analyze(null)['errorCount']);
        self::assertStringContainsString('could not be loaded', $analyzer->analyze(null)['status']);
    }

    public function testSidebarShowsDiagnosticsWithBrokenPreviewAndTransformer(): void
    {
        $metadata = $this->get(ComponentMetadataProvider::class)->getComponentMetadataForVariantIdentifier('site:missingTransformer:Default');
        self::assertNotNull($metadata?->missingTransformerError);
        $analyzer = new FluidTemplateAnalyzer($this->get(RenderingContextFactory::class), new HtmlSourceHighlighter());
        $analysis = $analyzer->analyze(new ComponentTemplateMetadata('Card', '/card.html', null, null, [], "<h2>Card</h2>\n<f:for as=\"item\" />\n<p>{title}</p>", null));
        $context = $this->get(RenderingContextFactory::class)->create();
        $context->getTemplatePaths()->setTemplateSource('{namespace frontend.studio=Andersundsehr\\FrontendStudio\\Components}<frontend.studio:variant.sidebar selectedComponentMetadata="{metadata}" variantActiveTab="html" renderedHtmlSource="" renderedHtmlStatus="Preview failed" fluidTemplateAnalysis="{analysis}" fluidUsageSource="{usage}" />');
        $view = new TemplateView($context);
        $view->assignMultiple(['metadata' => $metadata, 'analysis' => $analysis, 'usage' => ['tag' => '', 'inline' => '']]);

        $html = $view->render();
        self::assertStringContainsString('data-frontend-studio-template-count>1</span> errors', $html);
        self::assertStringNotContainsString('data-frontend-studio-template-badge hidden', $html);
        self::assertStringContainsString('is-error" data-line="2"', $html);
        self::assertStringContainsString('Preview failed', $html);
        self::assertStringNotContainsString('<iframe', $html);
    }

    public function testAnalysisRouteRequiresBackendToken(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/BackendUser.csv');
        $this->setUpBackendUser(1);
        $configuration = require __DIR__ . '/../../../Configuration/Backend/AjaxRoutes.php';
        $name = 'ajax_frontend_studio_template_analysis';
        $route = new Route($configuration['frontend_studio_template_analysis']['path'], [
            ...$configuration['frontend_studio_template_analysis'], '_identifier' => $name,
        ]);
        $request = new ServerRequest('https://example.test/typo3/ajax/frontend-studio/template-analysis')
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $route)
            ->withAttribute('backend.user', $GLOBALS['BE_USER']);
        $token = $this->get(FormProtectionFactory::class)->createFromRequest($request)->generateToken('route', $name);
        $response = $this->get(RouteDispatcher::class)->dispatch($request->withQueryParams([
            'componentVariant' => 'site:missingTransformer:Default', 'token' => $token,
        ]));
        self::assertSame(200, $response->getStatusCode());
        $this->expectException(InvalidRequestTokenException::class);
        $this->get(RouteDispatcher::class)->dispatch($request->withQueryParams(['token' => 'invalid']));
    }

    public function testEndpointWorksWithoutSiteOrTransformerAndRejectsUnknownSelection(): void
    {
        $controller = $this->get(FrontendStudioModuleController::class);
        $response = $controller->templateAction(new ServerRequest()->withQueryParams(['componentVariant' => 'site:missingTransformer:Default']));
        $result = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('', $result['status']);
        self::assertSame(0, $result['errorCount']);
        self::assertStringContainsString('data-line="1"', $result['source']);
        $response = $controller->templateAction(new ServerRequest()->withQueryParams(['componentVariant' => '/etc/passwd']));
        $result = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('', $result['source']);
        self::assertSame(1, $result['errorCount']);
    }
}
