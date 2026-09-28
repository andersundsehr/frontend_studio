<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Functional\Middleware;

use Andersundsehr\FrontendStudio\Middleware\ComponentPreviewContextMiddleware;
use Andersundsehr\FrontendStudio\Middleware\ComponentPreviewMiddleware;
use Andersundsehr\FrontendStudio\Middleware\ComponentVariantListMiddleware;
use Andersundsehr\FrontendStudio\Service\ComponentMetadataProvider;
use Andersundsehr\FrontendStudio\Service\ComponentPathResolverInterface;
use Andersundsehr\FrontendStudio\Service\ComponentPreviewRendererInterface;
use Andersundsehr\FrontendStudio\Service\ComponentTreeDataProvider;
use Andersundsehr\FrontendStudio\Service\FluidUsageSnippetRenderer;
use Andersundsehr\FrontendStudio\Service\HtmlSourceHighlighter;
use Andersundsehr\FrontendStudio\Service\PreviewAssetRenderer;
use Andersundsehr\FrontendStudio\Service\PreviewTypoScriptContextBuilder;
use Andersundsehr\FrontendStudio\Service\PreviewTypoScriptContextBuilderInterface;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Site\Set\SetRegistry;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScript;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

#[CoversClass(ComponentPreviewMiddleware::class)]
#[CoversClass(ComponentVariantListMiddleware::class)]
#[CoversClass(PreviewTypoScriptContextBuilder::class)]
final class ComponentPreviewWithoutPageTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        __DIR__ . '/../../..',
        __DIR__ . '/../Fixtures/Extensions/preview_site_set',
    ];

    protected array $pathsToProvideInTestInstance = [
        'typo3conf/ext/frontend_studio/Tests/Functional/Fixtures/Sites/preview' => 'typo3conf/sites/preview',
    ];

    public function testRendersWithSiteAndSetTypoScriptWhenTheRootPageRecordIsMissing(): void
    {
        self::assertNotNull($this->get(SetRegistry::class)->getSet('acme/frontend-studio-preview-test'));
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('preview');
        self::assertNotNull($site->getTypoScript());
        $language = $site->getDefaultLanguage();
        self::assertInstanceOf(SiteLanguage::class, $language);

        $queryBuilder = $this->get(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $pageRecord = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($site->getRootPageId(), ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
        self::assertFalse($pageRecord);
        $variants = $this->getListedVariants();
        self::assertCount(1, $variants);
        $variant = $variants[0];
        self::assertSame('site:card', $variant['componentName']);
        self::assertSame('Acme\\Preview\\Components', $variant['phpNamespace']);
        self::assertSame('Default', $variant['variantName']);
        self::assertSame('/__frontendStudio/preview?componentVariant=site%3Acard%3ADefault', $variant['url']);
        self::assertSame('/__frontendStudio/preview', parse_url($variant['url'], PHP_URL_PATH));
        parse_str(parse_url($variant['url'], PHP_URL_QUERY), $variantQuery);
        $variantIdentifier = $variantQuery['componentVariant'] ?? null;
        self::assertIsString($variantIdentifier);
        self::assertSame('site:card:Default', $variantIdentifier);

        $request = new ServerRequest('https://preview.test/de/?frontendStudioPreviewFormat=fragment&componentVariant=' . rawurlencode($variantIdentifier) . '&previewTest=yes')
            ->withQueryParams([
                'frontendStudioPreviewFormat' => 'fragment',
                'componentVariant' => $variantIdentifier,
                'previewTest' => 'yes',
            ])
            ->withAttribute(ComponentPreviewContextMiddleware::PREVIEW_ATTRIBUTE, true)
            ->withAttribute('site', $site)
            ->withAttribute('language', $language);
        $contextBuilder = $this->get(PreviewTypoScriptContextBuilderInterface::class);
        $renderRequest = $contextBuilder->build($request);
        $frontendTypoScript = $renderRequest->getAttribute('frontend.typoscript');
        self::assertInstanceOf(FrontendTypoScript::class, $frontendTypoScript);
        self::assertTrue($frontendTypoScript->hasPage(), json_encode($frontendTypoScript->getSetupConditionList(), JSON_THROW_ON_ERROR));
        self::assertSame('site-set-preview', $frontendTypoScript->getPageArray()['10.']['value'] ?? null);
        self::assertSame('site-file-preview', $frontendTypoScript->getPageArray()['20.']['value'] ?? null);
        $flatSettings = $frontendTypoScript->getFlatSettings();
        self::assertSame('settings-context-ready', $flatSettings['preview.context'] ?? null, json_encode($flatSettings, JSON_THROW_ON_ERROR));
        self::assertSame('site-file-constants', $flatSettings['preview.siteFile'] ?? null);
        self::assertSame($site, $renderRequest->getAttribute('site'));
        self::assertSame($language, $renderRequest->getAttribute('language'));
        $currentContentObject = $renderRequest->getAttribute('currentContentObject');
        self::assertInstanceOf(ContentObjectRenderer::class, $currentContentObject);
        self::assertSame($renderRequest, $currentContentObject->getRequest());
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

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('<article>Rendered from fixture</article>', (string)$response->getBody());
    }

    /**
     * @return list<array{url: string, componentName: string, phpNamespace: string, variantName: string}>
     */
    private function getListedVariants(): array
    {
        $middleware = new ComponentVariantListMiddleware(
            $this->get(ComponentTreeDataProvider::class),
            $this->get(ComponentMetadataProvider::class),
        );
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $middleware->process(
            new ServerRequest('https://preview.test/__frontendStudio/variants'),
            $handler,
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));

        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsArray($payload['variants'] ?? null);

        /** @var list<array{url: string, componentName: string, phpNamespace: string, variantName: string}> $variants */
        $variants = $payload['variants'];

        return $variants;
    }
}
