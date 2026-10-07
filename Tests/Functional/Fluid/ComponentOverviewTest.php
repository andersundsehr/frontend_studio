<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Functional\Fluid;

use Andersundsehr\FrontendStudio\Controller\FrontendStudioModuleController;
use Andersundsehr\FrontendStudio\Service\ComponentMetadataProvider;
use ReflectionMethod;
use TYPO3\CMS\Backend\Http\Application as BackendApplication;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\CookieScope;
use TYPO3\CMS\Core\Http\ServerRequest;
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
            self::assertStringContainsString('No documentation yet', $this->render($empty));
            self::assertStringContainsString('Component not found', $this->render($this->assignments('site:unknown')));
            self::assertSame("variants: {}\n", file_get_contents($path));
        } finally {
            file_put_contents($path, $original);
        }
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
                self::assertStringNotContainsString('data-frontend-studio-variant-sidebar', $html);
            } else {
                self::assertStringContainsString('data-frontend-studio-variant-sidebar', $html);
                self::assertStringNotContainsString('data-component-overview', $html);
            }
        }
    }

    /** @return array<string, mixed> */
    private function assignments(string $identifier): array
    {
        $method = new ReflectionMethod(FrontendStudioModuleController::class, 'getOverviewAssignments');
        return $method->invoke(
            $this->get(FrontendStudioModuleController::class),
            $identifier,
            new ServerRequest('https://example.test/typo3/module/admin/frontend-studio')->withQueryParams(['site' => 'preview', 'language' => 'de']),
        );
    }

    /** @param array<string, mixed> $assignments */
    private function render(array $assignments): string
    {
        $context = $this->get(RenderingContextFactory::class)->create([
            'templateRootPaths' => [__DIR__ . '/../../../Resources/Private/Templates'],
        ], new ServerRequest('https://example.test/typo3/module/admin/frontend-studio'));
        $view = new TemplateView($context);
        $view->assignMultiple($assignments);
        return (string)$view->render('FrontendStudio/Overview');
    }
}
