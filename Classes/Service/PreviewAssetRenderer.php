<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use Andersundsehr\FrontendStudio\Middleware\ComponentPreviewContextMiddleware;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Page\AssetRenderer;
use TYPO3\CMS\Core\Page\Event\ResolveVirtualJavaScriptImportEvent;
use TYPO3\CMS\Core\Page\PageRenderer;

use function array_filter;
use function implode;
use function str_contains;
use function trim;

use const PHP_EOL;

#[Autoconfigure(public: true)]
final readonly class PreviewAssetRenderer
{
    public function __construct(
        private AssetCollector $assetCollector,
        private AssetRenderer $assetRenderer,
        private PageRenderer $pageRenderer,
        private UriBuilder $uriBuilder,
        private LanguageServiceFactory $languageServiceFactory,
        #[Autowire(expression: 'service("package-dependent-cache-identifier").withAdditionalHashedIdentifier("JavaScriptLanguageDomain").toString()')]
        private string $javaScriptLanguageDomainCacheIdentifier,
    ) {
    }

    public function renderAssets(): string
    {
        $iframeContextId = ''; // no needed right now
        foreach ($this->assetCollector->getJavaScripts() as $identifier => $asset) {
            $asset['options']['external'] = true;
            $this->assetCollector->addJavaScript(
                $identifier,
                $this->changeUrl($asset['source'], $iframeContextId),
                $asset['attributes'],
                $asset['options']
            );
        }

        foreach ($this->assetCollector->getStyleSheets() as $identifier => $asset) {
            $asset['options']['external'] = true;
            $this->assetCollector->addStyleSheet(
                $identifier,
                $this->changeUrl($asset['source'], $iframeContextId),
                $asset['attributes'],
                $asset['options']
            );
        }

        return trim(implode(PHP_EOL, array_filter([
            $this->renderJavaScriptModules(),
            $this->assetRenderer->renderInlineJavaScript(true),
            $this->assetRenderer->renderInlineJavaScript(false),
            $this->assetRenderer->renderJavaScript(true),
            $this->assetRenderer->renderJavaScript(false),
            $this->assetRenderer->renderInlineStyleSheets(true),
            $this->assetRenderer->renderInlineStyleSheets(false),
            $this->assetRenderer->renderStyleSheets(true),
            $this->assetRenderer->renderStyleSheets(false),
        ])));
    }

    private function renderJavaScriptModules(): string
    {
        $modules = $this->assetCollector->getJavaScriptModules();
        if ($modules === []) {
            return '';
        }

        $GLOBALS['LANG'] ??= $this->languageServiceFactory->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);
        foreach ($modules as $module) {
            $this->pageRenderer->loadJavaScriptModule($module);
        }

        $sitePath = $GLOBALS['TYPO3_REQUEST']->getAttribute('normalizedParams')->getSitePath();
        $javaScriptRenderer = $this->pageRenderer->getJavaScriptRenderer();
        return $javaScriptRenderer->renderImportMap($sitePath) . $javaScriptRenderer->render(null, $sitePath);
    }

    #[AsEventListener(identifier: 'frontend-studio/resolve-virtual-label-import')]
    public function resolveVirtualLabelImport(ResolveVirtualJavaScriptImportEvent $event): void
    {
        if (($GLOBALS['TYPO3_REQUEST'] ?? null)?->getAttribute(ComponentPreviewContextMiddleware::PREVIEW_ATTRIBUTE) !== true) {
            return;
        }

        if ($event->resolution === null && $event->virtualName === 'labels/') {
            $event->resolution = str_replace('/__DOMAIN__', '', (string)$this->uriBuilder->buildUriFromRoute('language_domain', [
                'locale' => $GLOBALS['LANG']->getLocale()?->getName() ?? 'en',
                'cacheBustInfix' => $this->javaScriptLanguageDomainCacheIdentifier,
                'domain' => '__DOMAIN__',
            ])) . '/';
        }
    }

    private function changeUrl(string $source, string $iframeContextId): string
    {
        if (str_starts_with($source, 'EXT:') || str_starts_with($source, 'PKG:')) {
            return $source;
        }

        if (str_contains($source, '/@vite')) {
            // if you include /@vite/client or /@vite-plugin-checker-runtime-entry
            // we do not want to reload it every time as that is not necessary
            return $source;
        }

        // add cache bust so the module is reevaluated on each render
        // this is necessary because the iframe is not reloaded and the module not re-evaluated automatically
        if (str_contains($source, '#')) {
            return $source . '&id=' . $iframeContextId;
        }

        return $source . '#id=' . $iframeContextId;
    }
}
