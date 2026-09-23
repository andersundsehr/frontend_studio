<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Controller;

use Andersundsehr\FrontendStudio\Dto\ComponentMetadata;
use Andersundsehr\FrontendStudio\Service\ComponentMetadataProvider;
use Andersundsehr\FrontendStudio\Service\ComponentPreviewRenderer;
use Andersundsehr\FrontendStudio\Service\FluidUsageSnippetRenderer;
use Andersundsehr\FrontendStudio\Service\HtmlSourceHighlighter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Site\SiteFinder;
use Throwable;

#[AsController]
final readonly class FrontendStudioModuleController
{
    private const string VARIANT_SIDEBAR_WIDTH_USER_SETTING = 'frontendStudio.variantView.sidebarWidth';

    private const string VARIANT_ACTIVE_TAB_USER_SETTING = 'frontendStudio.variantView.activeTab';

    private const int DEFAULT_VARIANT_SIDEBAR_WIDTH = 360;

    private const int MINIMUM_VARIANT_SIDEBAR_WIDTH = 280;

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ComponentMetadataProvider $componentMetadataProvider,
        private ComponentPreviewRenderer $componentPreviewRenderer,
        private FluidUsageSnippetRenderer $fluidUsageSnippetRenderer,
        private HtmlSourceHighlighter $htmlSourceHighlighter,
        private UriBuilder $uriBuilder,
        private SiteFinder $siteFinder,
        private Typo3Version $typo3Version,
        private AssetCollector $assetCollector,
    ) {
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderVariantViewResponse($request);
    }

    private function renderVariantViewResponse(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $queryParams = $request->getQueryParams();
        $selectedVariantIdentifier = isset($queryParams['componentVariant'])
            ? (string)$queryParams['componentVariant']
            : '';

        $moduleTemplate->setTitle($selectedVariantIdentifier !== '' ? $selectedVariantIdentifier : 'Frontend Studio');
        $moduleTemplate->getDocHeaderComponent()->disable();
        $moduleTemplate->assignMultiple($this->getVariantAssignments($selectedVariantIdentifier, $request));

        if ($this->typo3Version->getMajorVersion() >= 14) {
            $this->assetCollector->addJavaScriptModule('@typo3/backend/viewport/content-navigation-toggle.js');
        }

        return $moduleTemplate->renderResponse('FrontendStudio/Variant');
    }

    /**
     * @return array<string, mixed>
     */
    private function getVariantAssignments(string $selectedVariantIdentifier, ServerRequestInterface $request): array
    {
        $selectedComponentMetadata = $this->componentMetadataProvider->getComponentMetadataForVariantIdentifier($selectedVariantIdentifier);
        [$renderedHtmlSource, $renderedHtmlStatus] = $selectedComponentMetadata !== null
            ? $this->renderInitialHtmlSource($selectedVariantIdentifier, $request)
            : ['', ''];

        return [
            'selectedVariantIdentifier' => $selectedVariantIdentifier,
            'selectedComponentMetadata' => $selectedComponentMetadata,
            'componentPreviewUri' => $selectedComponentMetadata !== null
                ? $this->buildComponentPreviewUri($selectedVariantIdentifier, $request)
                : null,
            'variantSidebarWidth' => $this->getVariantSidebarWidth($GLOBALS['BE_USER']->uc ?? []),
            'variantActiveTab' => $this->getVariantActiveTab($GLOBALS['BE_USER']->uc ?? []),
            'renderedHtmlSource' => $renderedHtmlSource,
            'renderedHtmlStatus' => $renderedHtmlStatus,
            'fluidTemplateSource' => $this->renderFluidTemplateSource($selectedComponentMetadata),
            'fluidUsageSource' => $this->fluidUsageSnippetRenderer->render($selectedComponentMetadata),
            'componentChangeStreamUri' => Environment::getContext()->isDevelopment()
                ? (string)$this->uriBuilder->buildUriFromRoute('ajax_frontend_studio_component_change_stream')
                : '',
        ];
    }

    /**
     * @return array{string, string}
     */
    private function renderInitialHtmlSource(string $selectedVariantIdentifier, ServerRequestInterface $request): array
    {
        try {
            return [
                $this->htmlSourceHighlighter->highlight(
                    $this->componentPreviewRenderer->renderVariant($selectedVariantIdentifier, $request),
                ),
                '',
            ];
        } catch (Throwable $throwable) {
            return [
                '',
                'The rendered HTML could not be loaded. ' . $throwable->getMessage(),
            ];
        }
    }

    private function renderFluidTemplateSource(?ComponentMetadata $selectedComponentMetadata): string
    {
        $templateContent = $selectedComponentMetadata?->template?->content;
        if (!is_string($templateContent) || $templateContent === '') {
            return '';
        }

        return $this->htmlSourceHighlighter->highlightFluidTemplate($templateContent);
    }

    /**
     * @param array<string, mixed> $backendUserSettings
     */
    private function getVariantSidebarWidth(array $backendUserSettings): int
    {
        $configuredWidth = $this->getBackendUserSettingByDottedPath(
            $backendUserSettings,
            self::VARIANT_SIDEBAR_WIDTH_USER_SETTING,
        );

        if (!is_int($configuredWidth) && (!is_string($configuredWidth) || preg_match('/^\d+$/', $configuredWidth) !== 1)) {
            return self::DEFAULT_VARIANT_SIDEBAR_WIDTH;
        }

        return max((int)$configuredWidth, self::MINIMUM_VARIANT_SIDEBAR_WIDTH);
    }

    /**
     * @param array<string, mixed> $backendUserSettings
     */
    private function getVariantActiveTab(array $backendUserSettings): string
    {
        $configuredActiveTab = $this->getBackendUserSettingByDottedPath(
            $backendUserSettings,
            self::VARIANT_ACTIVE_TAB_USER_SETTING,
        );

        if (!is_string($configuredActiveTab) || !in_array($configuredActiveTab, ['values', 'html', 'template', 'usage'], true)) {
            return 'values';
        }

        return $configuredActiveTab;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function getBackendUserSettingByDottedPath(array $settings, string $path): mixed
    {
        $value = $settings;
        foreach (explode('.', $path) as $pathSegment) {
            if (!is_array($value) || !array_key_exists($pathSegment, $value)) {
                return null;
            }

            $value = $value[$pathSegment];
        }

        return $value;
    }

    private function buildComponentPreviewUri(string $selectedVariantIdentifier, ServerRequestInterface $request): ?string
    {
        try {
            $sites = $this->siteFinder->getAllSites();
            $site = reset($sites);
            if ($site === false) {
                return null;
            }

            $requestOrigin = $this->getRequestOrigin($request);
            if ($requestOrigin !== null) {
                foreach ($sites as $candidateSite) {
                    $candidateBase = (string)$candidateSite->getDefaultLanguage()->getBase();
                    if ($this->getUrlOrigin($candidateBase) === $requestOrigin) {
                        $site = $candidateSite;
                        break;
                    }
                }
            }

            return rtrim((string)$site->getDefaultLanguage()->getBase(), '/') . '/?'
                . http_build_query([
                    'frontendStudioComponentPreview' => '1',
                    'componentVariant' => $selectedVariantIdentifier,
                ], '', '&', PHP_QUERY_RFC3986);
        } catch (Throwable) {
            return null;
        }
    }

    private function getRequestOrigin(ServerRequestInterface $request): ?string
    {
        $uri = $request->getUri();
        return $this->buildOrigin($uri->getScheme(), $uri->getHost(), $uri->getPort());
    }

    private function getUrlOrigin(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = isset($parts['scheme']) ? (string)$parts['scheme'] : '';
        $host = isset($parts['host']) ? (string)$parts['host'] : '';
        $port = isset($parts['port']) && is_int($parts['port']) ? $parts['port'] : null;

        return $this->buildOrigin($scheme, $host, $port);
    }

    private function buildOrigin(string $scheme, string $host, ?int $port): ?string
    {
        $scheme = strtolower($scheme);
        $host = strtolower($host);
        if ($scheme === '' || $host === '') {
            return null;
        }

        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        return $scheme . '://' . $host . ($port !== null ? ':' . $port : '');
    }
}
