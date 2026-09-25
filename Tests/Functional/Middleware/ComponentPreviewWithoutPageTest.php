<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Functional\Middleware;

use Andersundsehr\FrontendStudio\Middleware\ComponentPreviewContextMiddleware;
use Andersundsehr\FrontendStudio\Middleware\ComponentPreviewMiddleware;
use Andersundsehr\FrontendStudio\Service\ComponentMetadataProvider;
use Andersundsehr\FrontendStudio\Service\ComponentPathResolverInterface;
use Andersundsehr\FrontendStudio\Service\ComponentPreviewRendererInterface;
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
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\Entity\SiteTypoScript;
use TYPO3\CMS\Core\Site\Set\SetRegistry;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScript;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

#[CoversClass(ComponentPreviewMiddleware::class)]
#[CoversClass(PreviewTypoScriptContextBuilder::class)]
final class ComponentPreviewWithoutPageTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        __DIR__ . '/../../..',
        __DIR__ . '/../Fixtures/Extensions/preview_site_set',
    ];

    public function testRendersWithSiteSetTypoScriptWhenTheRootPageRecordIsMissing(): void
    {
        self::assertNotNull($this->get(SetRegistry::class)->getSet('acme/frontend-studio-preview-test'));
        $site = new Site(
            'preview',
            98765432,
            [
                'base' => 'https://preview.test/',
                'dependencies' => ['acme/frontend-studio-preview-test'],
                'languages' => [
                    0 => [
                        'languageId' => 0,
                        'title' => 'German',
                        'locale' => 'de-DE',
                        'hreflang' => 'de',
                        'base' => 'https://preview.test/de/',
                    ],
                ],
            ],
            typoscript: new SiteTypoScript(
                setup: "page = PAGE\npage.10 = TEXT\npage.10.value = inline-site-preview",
                constants: 'preview.context = inline-site-context',
            ),
        );
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
        $request = (new ServerRequest('https://preview.test/de/?frontendStudioPreviewFormat=fragment&componentVariant=site%3Acard%3ADefault&previewTest=yes'))
            ->withQueryParams([
                'frontendStudioPreviewFormat' => 'fragment',
                'componentVariant' => 'site:card:Default',
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
        $flatSettings = $frontendTypoScript->getFlatSettings();
        self::assertSame('settings-context-ready', $flatSettings['preview.context'] ?? null, json_encode($flatSettings, JSON_THROW_ON_ERROR));
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
}
