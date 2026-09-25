<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Controller;

use Andersundsehr\FrontendStudio\Controller\FrontendStudioModuleController;
use Andersundsehr\FrontendStudio\Service\PreviewContextResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

#[CoversClass(FrontendStudioModuleController::class)]
final class FrontendStudioModuleControllerPreviewUriTest extends TestCase
{
    public function testBuildsTheSameOriginPreviewEndpointWithSelectedContext(): void
    {
        $controller = (new ReflectionClass(FrontendStudioModuleController::class))->newInstanceWithoutConstructor();
        $buildComponentPreviewUri = new ReflectionMethod(FrontendStudioModuleController::class, 'buildComponentPreviewUri');
        $uri = $buildComponentPreviewUri->invoke(
            $controller,
            'site:Card:Special Variant',
            'main-site',
            ['value' => 'de-AT', 'title' => 'German'],
        );
        self::assertIsString($uri);

        $parts = parse_url($uri);
        self::assertIsArray($parts);
        self::assertSame('/__frontendStudio/preview', $parts['path'] ?? null);
        self::assertArrayNotHasKey('host', $parts);
        parse_str($parts['query'] ?? '', $queryParams);
        self::assertSame('site:Card:Special Variant', $queryParams['componentVariant'] ?? null);
        self::assertSame('main-site', $queryParams['site'] ?? null);
        self::assertSame('de-AT', $queryParams['language'] ?? null);
        self::assertArrayNotHasKey('frontendStudioComponentPreview', $queryParams);
    }

    public function testUsesTheSharedResolverForTheBackendPreviewSelection(): void
    {
        $firstSite = $this->createSite('first', 'first.test');
        $originSite = $this->createSite('origin', 'incoming.test');
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->expects(self::exactly(2))->method('getAllSites')->willReturn([
            'first' => $firstSite,
            'origin' => $originSite,
        ]);
        $controller = (new ReflectionClass(FrontendStudioModuleController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(FrontendStudioModuleController::class, 'siteFinder'))->setValue($controller, $siteFinder);
        (new ReflectionProperty(FrontendStudioModuleController::class, 'previewContextResolver'))
            ->setValue($controller, new PreviewContextResolver($siteFinder));
        $request = (new ServerRequest('https://incoming.test/typo3/module?language=de'))
            ->withQueryParams(['language' => 'de']);
        $getPreviewContext = new ReflectionMethod(FrontendStudioModuleController::class, 'getPreviewContext');

        $context = $getPreviewContext->invoke($controller, 'site:Card:Default', $request);

        self::assertSame('origin', $context['selectedSiteIdentifier']);
        self::assertSame('de', $context['selectedLanguageHreflang']);
        self::assertSame(
            '/__frontendStudio/preview?componentVariant=site%3ACard%3ADefault&site=origin&language=de',
            $context['componentPreviewUri'],
        );
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
}
