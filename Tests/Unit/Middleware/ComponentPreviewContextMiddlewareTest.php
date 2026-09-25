<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Middleware;

use Andersundsehr\FrontendStudio\Middleware\ComponentPreviewContextMiddleware;
use Andersundsehr\FrontendStudio\Service\PreviewContextResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Service\DependencyOrderingService;

#[CoversClass(ComponentPreviewContextMiddleware::class)]
final class ComponentPreviewContextMiddlewareTest extends TestCase
{
    public function testIgnoresTheLegacyQueryMarkerOutsideThePreviewEndpoint(): void
    {
        $request = new ServerRequest('https://incoming.test/original/?frontendStudioComponentPreview=1')
            ->withQueryParams(['frontendStudioComponentPreview' => '1']);
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->expects(self::never())->method('getSiteByIdentifier');
        $siteFinder->expects(self::never())->method('getAllSites');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::identicalTo($request))
            ->willReturn(new HtmlResponse('normal frontend response'));

        $response = $this->createMiddleware($siteFinder)->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testIgnoresSiteAndLanguageParametersOnNonPreviewRequests(): void
    {
        $request = new ServerRequest('https://incoming.test/original/?site=other&language=de')
            ->withQueryParams(['site' => 'other', 'language' => 'de']);
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->expects(self::never())->method('getSiteByIdentifier');
        $siteFinder->expects(self::never())->method('getAllSites');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::identicalTo($request))
            ->willReturn(new HtmlResponse('normal frontend response'));

        $response = $this->createMiddleware($siteFinder)->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testUsesTheRequestOriginAndFirstLanguageWhenContextIsOmitted(): void
    {
        $firstSite = $this->createSite('first', 'first.test');
        $originSite = $this->createSite('origin', 'incoming.test');
        $request = $this->createPreviewRequest();
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->expects(self::once())->method('getAllSites')->willReturn([
            'first' => $firstSite,
            'origin' => $originSite,
        ]);
        $siteFinder->expects(self::once())->method('getSiteByIdentifier')->with('origin')->willReturn($originSite);
        $handler = $this->createRewriteHandler($request, $this->getFirstLanguage($originSite));

        $response = $this->createMiddleware($siteFinder)->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testFallsBackToTheFirstConfiguredSiteWhenTheRequestOriginDoesNotMatch(): void
    {
        $firstSite = $this->createSite('first', 'first.test');
        $secondSite = $this->createSite('second', 'second.test');
        $request = $this->createPreviewRequest();
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->expects(self::once())->method('getAllSites')->willReturn([
            'first' => $firstSite,
            'second' => $secondSite,
        ]);
        $siteFinder->expects(self::once())->method('getSiteByIdentifier')->with('first')->willReturn($firstSite);
        $handler = $this->createRewriteHandler($request, $this->getFirstLanguage($firstSite));

        $response = $this->createMiddleware($siteFinder)->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testRewritesToTheRequestedSiteAndLanguageBaseAndPreservesTheQuery(): void
    {
        $site = $this->createSite('target', 'target.test');
        $request = $this->createPreviewRequest(['site' => 'target', 'language' => 'de']);
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->expects(self::once())->method('getAllSites')->willReturn(['target' => $site]);
        $siteFinder->expects(self::once())
            ->method('getSiteByIdentifier')
            ->with('target')
            ->willReturn($site);
        $handler = $this->createRewriteHandler($request, $site->getLanguageById(1));

        $response = $this->createMiddleware($siteFinder)->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAddsTheIncomingAuthorityToARelativeLanguageBase(): void
    {
        $site = $this->createSiteWithRelativeLanguageBases();
        $request = $this->createPreviewRequest(
            ['site' => 'relative', 'language' => 'de'],
            'http://preview.test:8123',
        );
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->expects(self::once())->method('getAllSites')->willReturn(['relative' => $site]);
        $siteFinder->expects(self::once())
            ->method('getSiteByIdentifier')
            ->with('relative')
            ->willReturn($site);
        $expectedUri = 'http://preview.test:8123/de/?' . $request->getUri()->getQuery();
        $handler = $this->createRewriteHandler($request, $site->getLanguageById(1), $expectedUri);

        $response = $this->createMiddleware($siteFinder)->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testResolvesAnExplicitLanguageAgainstTheOriginSelectedSite(): void
    {
        $site = $this->createSite('main', 'incoming.test');
        $request = $this->createPreviewRequest(['language' => 'de']);
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->expects(self::once())->method('getAllSites')->willReturn(['main' => $site]);
        $siteFinder->expects(self::once())->method('getSiteByIdentifier')->with('main')->willReturn($site);
        $handler = $this->createRewriteHandler($request, $site->getLanguageById(1));

        $response = $this->createMiddleware($siteFinder)->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testUsesTheSelectedSitesFirstLanguageWhenLanguageIsOmitted(): void
    {
        $site = $this->createSite('selected', 'selected.test');
        $request = $this->createPreviewRequest(['site' => 'selected']);
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->expects(self::once())->method('getAllSites')->willReturn(['selected' => $site]);
        $siteFinder->expects(self::once())
            ->method('getSiteByIdentifier')
            ->with('selected')
            ->willReturn($site);
        $handler = $this->createRewriteHandler($request, $this->getFirstLanguage($site));

        $response = $this->createMiddleware($siteFinder)->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testReturnsNotFoundForAnUnknownSite(): void
    {
        $request = $this->createPreviewRequest(['site' => 'missing']);
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->expects(self::once())->method('getAllSites')->willReturn([
            'first' => $this->createSite('first', 'first.test'),
        ]);
        $siteFinder->expects(self::never())->method('getSiteByIdentifier');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $this->createMiddleware($siteFinder)->process($request, $handler);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testReturnsNotFoundForAnUnknownHreflang(): void
    {
        $site = $this->createSite('target', 'target.test');
        $request = $this->createPreviewRequest(['site' => 'target', 'language' => 'missing']);
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->expects(self::once())->method('getAllSites')->willReturn(['target' => $site]);
        $siteFinder->expects(self::never())->method('getSiteByIdentifier');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $this->createMiddleware($siteFinder)->process($request, $handler);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testReturnsNotFoundWhenNoSitesAreConfigured(): void
    {
        $request = $this->createPreviewRequest();
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->expects(self::once())->method('getAllSites')->willReturn([]);
        $siteFinder->expects(self::never())->method('getSiteByIdentifier');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $this->createMiddleware($siteFinder)->process($request, $handler);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testReturnsBadRequestForMalformedContextParameters(): void
    {
        $request = new ServerRequest('https://incoming.test/__frontendStudio/preview?site=invalid')
            ->withQueryParams(['site' => ['invalid']]);
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->expects(self::never())->method('getSiteByIdentifier');
        $siteFinder->expects(self::never())->method('getAllSites');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $this->createMiddleware($siteFinder)->process($request, $handler);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testPreviewMiddlewareUsesTheResolvedSiteAndBackendUserBeforePageResolution(): void
    {
        $requestMiddlewares = require dirname(__DIR__, 3) . '/Configuration/RequestMiddlewares.php';
        $contextMiddleware = $requestMiddlewares['frontend']['andersundsehr/frontend-studio/component-preview-context'];
        $previewMiddleware = $requestMiddlewares['frontend']['andersundsehr/frontend-studio/component-preview'];
        $typo3RequestMiddlewares = require dirname(__DIR__, 3) . '/vendor/typo3/cms-frontend/Configuration/RequestMiddlewares.php';
        $orderedMiddlewareIdentifiers = array_keys(new DependencyOrderingService()->orderByDependencies(
            array_replace($typo3RequestMiddlewares['frontend'], $requestMiddlewares['frontend']),
        ));
        $middlewarePositions = array_flip($orderedMiddlewareIdentifiers);

        self::assertSame(ComponentPreviewContextMiddleware::class, $contextMiddleware['target']);
        self::assertSame(['typo3/cms-core/normalized-params-attribute'], $contextMiddleware['after']);
        self::assertSame(['typo3/cms-frontend/site'], $contextMiddleware['before']);
        self::assertContains('typo3/cms-frontend/site', $previewMiddleware['after']);
        self::assertContains('typo3/cms-frontend/maintenance-mode', $previewMiddleware['after']);
        self::assertContains('typo3/cms-frontend/backend-user-authentication', $previewMiddleware['after']);
        self::assertContains('typo3/cms-frontend/authentication', $previewMiddleware['before']);
        self::assertContains('typo3/cms-frontend/page-resolver', $previewMiddleware['before']);
        self::assertNotContains('typo3/cms-frontend/prepare-tsfe-rendering', $previewMiddleware['after']);
        self::assertLessThan($middlewarePositions['typo3/cms-frontend/site'], $middlewarePositions['andersundsehr/frontend-studio/component-preview-context']);
        self::assertLessThan($middlewarePositions['andersundsehr/frontend-studio/component-preview'], $middlewarePositions['typo3/cms-frontend/site']);
        self::assertLessThan($middlewarePositions['andersundsehr/frontend-studio/component-preview'], $middlewarePositions['typo3/cms-frontend/maintenance-mode']);
        self::assertLessThan($middlewarePositions['andersundsehr/frontend-studio/component-preview'], $middlewarePositions['typo3/cms-frontend/backend-user-authentication']);
        self::assertLessThan($middlewarePositions['typo3/cms-frontend/authentication'], $middlewarePositions['andersundsehr/frontend-studio/component-preview']);
        self::assertLessThan($middlewarePositions['typo3/cms-frontend/page-resolver'], $middlewarePositions['andersundsehr/frontend-studio/component-preview']);
    }

    /** @param array<string, mixed> $context */
    private function createPreviewRequest(array $context = [], string $origin = 'https://incoming.test'): ServerRequest
    {
        $queryParams = [...$context, 'keep' => 'yes'];
        $query = http_build_query($queryParams);
        $uri = $origin . '/__frontendStudio/preview' . ($query !== '' ? '?' . $query : '');

        return new ServerRequest($uri)->withQueryParams($queryParams);
    }

    private function createRewriteHandler(
        ServerRequestInterface $originalRequest,
        SiteLanguage $language,
        ?string $expectedUri = null,
    ): RequestHandlerInterface {
        $expectedUri ??= (string)$language->getBase()->withQuery($originalRequest->getUri()->getQuery());
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(static function (ServerRequestInterface $routedRequest) use ($expectedUri): bool {
                self::assertSame($expectedUri, (string)$routedRequest->getUri());
                self::assertTrue($routedRequest->getAttribute(ComponentPreviewContextMiddleware::PREVIEW_ATTRIBUTE));

                return true;
            }))
            ->willReturn(new HtmlResponse('preview'));

        return $handler;
    }

    private function createSite(string $identifier, string $host): Site
    {
        return new Site($identifier, 1, [
            'base' => 'https://' . $host . '/',
            'languages' => [
                0 => [
                    'languageId' => 0,
                    'title' => 'English',
                    'locale' => 'en-US',
                    'hreflang' => 'en',
                    'base' => 'https://' . $host . '/',
                ],
                1 => [
                    'languageId' => 1,
                    'title' => 'German',
                    'locale' => 'de-AT',
                    'hreflang' => 'de',
                    'base' => 'https://' . $host . '/de/',
                ],
            ],
        ]);
    }

    private function createSiteWithRelativeLanguageBases(): Site
    {
        return new Site('relative', 1, [
            'base' => '/',
            'languages' => [
                0 => [
                    'languageId' => 0,
                    'title' => 'English',
                    'locale' => 'en-US',
                    'hreflang' => 'en',
                    'base' => '/',
                ],
                1 => [
                    'languageId' => 1,
                    'title' => 'German',
                    'locale' => 'de-AT',
                    'hreflang' => 'de',
                    'base' => '/de/',
                ],
            ],
        ]);
    }

    private function getFirstLanguage(Site $site): SiteLanguage
    {
        foreach ($site->getLanguages() as $language) {
            return $language;
        }

        self::fail('Test site has no languages.');
    }

    private function createMiddleware(SiteFinder $siteFinder): ComponentPreviewContextMiddleware
    {
        return new ComponentPreviewContextMiddleware($siteFinder, new PreviewContextResolver($siteFinder));
    }
}
