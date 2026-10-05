<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Controller;

use Andersundsehr\FrontendStudio\Dto\ComponentMetadata;
use Andersundsehr\FrontendStudio\Service\ComponentMetadataProvider;
use Andersundsehr\FrontendStudio\Service\ComponentPreviewRenderer;
use Andersundsehr\FrontendStudio\Service\FluidUsageSnippetRenderer;
use Andersundsehr\FrontendStudio\Service\HtmlSourceHighlighter;
use Andersundsehr\FrontendStudio\Service\PreviewContextResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use Throwable;

#[AsController]
final readonly class FrontendStudioModuleController
{
    private const string VARIANT_SIDEBAR_WIDTH_USER_SETTING = 'frontendStudio.variantView.sidebarWidth';

    private const string VARIANT_SIDEBAR_HEIGHT_USER_SETTING = 'frontendStudio.variantView.sidebarHeight';

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
        private PreviewContextResolver $previewContextResolver,
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
        $previewContext = $this->getPreviewContext($selectedVariantIdentifier, $request);
        [$renderedHtmlSource, $renderedHtmlStatus] = $selectedComponentMetadata !== null
            ? $this->renderInitialHtmlSource($selectedVariantIdentifier, $request)
            : ['', ''];

        return [
            'selectedVariantIdentifier' => $selectedVariantIdentifier,
            'selectedComponentMetadata' => $selectedComponentMetadata,
            'componentPreviewUri' => $selectedComponentMetadata !== null ? $previewContext['componentPreviewUri'] : null,
            'previewSites' => $previewContext['sites'],
            'previewLanguages' => $previewContext['languages'],
            'selectedSiteIdentifier' => $previewContext['selectedSiteIdentifier'],
            'selectedLanguageHreflang' => $previewContext['selectedLanguageHreflang'],
            'variantSidebarWidth' => $this->getVariantSidebarWidth($GLOBALS['BE_USER']->uc ?? []),
            'variantSidebarHeight' => $this->getVariantSidebarHeight($GLOBALS['BE_USER']->uc ?? []),
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
    private function getVariantSidebarHeight(array $backendUserSettings): ?int
    {
        $configuredHeight = $this->getBackendUserSettingByDottedPath(
            $backendUserSettings,
            self::VARIANT_SIDEBAR_HEIGHT_USER_SETTING,
        );

        if (!is_int($configuredHeight) && (!is_string($configuredHeight) || preg_match('/^\d+$/', $configuredHeight) !== 1)) {
            return null;
        }

        return max((int)$configuredHeight, 0);
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

    /**
     * @return array{
     *     sites: list<array{identifier: string, title: string, languagesJson: string}>,
     *     languages: list<array{value: string, title: string}>,
     *     selectedSiteIdentifier: string,
     *     selectedLanguageHreflang: string,
     *     componentPreviewUri: ?string
     * }
     */
    private function getPreviewContext(string $selectedVariantIdentifier, ServerRequestInterface $request): array
    {
        $emptyContext = [
            'sites' => [],
            'languages' => [],
            'selectedSiteIdentifier' => '',
            'selectedLanguageHreflang' => '',
            'componentPreviewUri' => null,
        ];

        try {
            $sites = array_values($this->siteFinder->getAllSites());
            if ($sites === []) {
                return $emptyContext;
            }

            $languagesBySite = [];
            $siteOptions = [];
            foreach ($sites as $site) {
                $siteIdentifier = $site->getIdentifier();
                $languages = $this->getSiteLanguageOptions($site);
                $languagesBySite[$siteIdentifier] = $languages;
                $websiteTitle = (string)($site->getConfiguration()['websiteTitle'] ?? '');
                $siteOptions[] = [
                    'identifier' => $siteIdentifier,
                    'title' => $websiteTitle !== '' ? $websiteTitle : $siteIdentifier,
                    'languagesJson' => json_encode(
                        $languages,
                        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR,
                    ) ?: '[]',
                ];
            }

            $queryParams = $request->getQueryParams();
            $resolvedContext = $this->previewContextResolver->resolve(
                $request,
                is_string($queryParams['site'] ?? null) ? $queryParams['site'] : null,
                is_string($queryParams['language'] ?? null) ? $queryParams['language'] : null,
            );
            if ($resolvedContext === null) {
                return $emptyContext;
            }

            [$selectedSiteIdentifier, $selectedLanguageHreflang] = $resolvedContext;
            $languages = $languagesBySite[$selectedSiteIdentifier] ?? [];
            $selectedLanguage = array_find($languages, fn($language): bool => $language['value'] === $selectedLanguageHreflang);

            return [
                'sites' => $siteOptions,
                'languages' => $languages,
                'selectedSiteIdentifier' => $selectedSiteIdentifier,
                'selectedLanguageHreflang' => $selectedLanguage['value'] ?? '',
                'componentPreviewUri' => $this->buildComponentPreviewUri(
                    $selectedVariantIdentifier,
                    $selectedSiteIdentifier,
                    $selectedLanguage,
                ),
            ];
        } catch (Throwable) {
            return $emptyContext;
        }
    }

    /**
     * @return list<array{value: string, title: string}>
     */
    private function getSiteLanguageOptions(Site $site): array
    {
        $options = [];
        foreach ($site->getLanguages() as $language) {
            $options[] = [
                'value' => $language->getHreflang(),
                'title' => $this->getCountryFlagEmoji($language->getLocale()->getCountryCode()) . ' ' . $language->getTitle(),
            ];
        }

        return $options;
    }

    private function getCountryFlagEmoji(?string $countryIsoAlpha2): string
    {
        if ($countryIsoAlpha2 === null) {
            return '🏳️‍🌈';
        }

        $countryIsoAlpha2 = strtoupper($countryIsoAlpha2);
        if (strlen($countryIsoAlpha2) !== 2) {
            return '🏳️‍🌈';
        }

        $firstCountryLetter = ord($countryIsoAlpha2[0]);
        $secondCountryLetter = ord($countryIsoAlpha2[1]);
        if (
            $firstCountryLetter < 0x41
            || $firstCountryLetter > 0x5A
            || $secondCountryLetter < 0x41
            || $secondCountryLetter > 0x5A
        ) {
            return '🏳️‍🌈';
        }

        $unicodePrefix = "\xF0\x9F\x87";
        $unicodeAdditionForUpperCase = 0x65;

        return $unicodePrefix . chr($firstCountryLetter + $unicodeAdditionForUpperCase)
            . $unicodePrefix . chr($secondCountryLetter + $unicodeAdditionForUpperCase);
    }

    /**
     * @param array{value: string, title: string}|null $selectedLanguage
     */
    private function buildComponentPreviewUri(
        string $selectedVariantIdentifier,
        string $selectedSiteIdentifier,
        ?array $selectedLanguage,
    ): ?string {
        if ($selectedVariantIdentifier === '' || $selectedSiteIdentifier === '' || $selectedLanguage === null) {
            return null;
        }

        return '/__frontendStudio/preview?'
            . http_build_query([
                'componentVariant' => $selectedVariantIdentifier,
                'site' => $selectedSiteIdentifier,
                'language' => $selectedLanguage['value'],
            ], '', '&', PHP_QUERY_RFC3986);
    }
}
