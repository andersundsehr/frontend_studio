<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Controller;

use Andersundsehr\FrontendStudio\Service\ComponentMetadataProvider;
use Andersundsehr\FrontendStudio\Service\ComponentPreviewRenderer;
use Andersundsehr\FrontendStudio\Service\HtmlSourceHighlighter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use Throwable;

#[AsController]
final readonly class FrontendStudioModuleController
{
    private const VARIANT_SIDEBAR_WIDTH_USER_SETTING = 'frontendStudio.variantView.sidebarWidth';
    private const VARIANT_ACTIVE_TAB_USER_SETTING = 'frontendStudio.variantView.activeTab';
    private const DEFAULT_VARIANT_SIDEBAR_WIDTH = 360;
    private const MINIMUM_VARIANT_SIDEBAR_WIDTH = 280;

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ComponentMetadataProvider $componentMetadataProvider,
        private ComponentPreviewRenderer $componentPreviewRenderer,
        private HtmlSourceHighlighter $htmlSourceHighlighter,
        private SiteFinder $siteFinder,
    ) {}

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
                ? $this->buildComponentPreviewUri($selectedVariantIdentifier)
                : null,
            'variantSidebarWidth' => $this->getVariantSidebarWidth($GLOBALS['BE_USER']->uc ?? []),
            'variantActiveTab' => $this->getVariantActiveTab($GLOBALS['BE_USER']->uc ?? []),
            'renderedHtmlSource' => $renderedHtmlSource,
            'renderedHtmlStatus' => $renderedHtmlStatus,
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

    /**
     * @param array<string, mixed> $backendUserSettings
     */
    private function getVariantSidebarWidth(array $backendUserSettings): int
    {
        $configuredWidth = $this->getBackendUserSettingByDottedPath(
            $backendUserSettings,
            self::VARIANT_SIDEBAR_WIDTH_USER_SETTING,
        );

        if (!is_int($configuredWidth) && !(is_string($configuredWidth) && preg_match('/^\d+$/', $configuredWidth) === 1)) {
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

        if (!is_string($configuredActiveTab) || !in_array($configuredActiveTab, ['values', 'html'], true)) {
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

    private function buildComponentPreviewUri(string $selectedVariantIdentifier): ?string
    {
        try {
            $sites = $this->siteFinder->getAllSites();
            $site = reset($sites);
            if ($site === false) {
                return null;
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
}
