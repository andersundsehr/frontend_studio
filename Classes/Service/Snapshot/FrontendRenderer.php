<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service\Snapshot;

use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use Andersundsehr\FrontendStudio\Service\ComponentPreviewRendererInterface;
use Andersundsehr\FrontendStudio\Service\PreviewTypoScriptContextBuilderInterface;
use RuntimeException;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Resource\Event\GeneratePublicUrlForResourceEvent;
use TYPO3\CMS\Frontend\Resource\PublicUrlPrefixer;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Context\LanguageAspectFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Routing\SiteRouteResult;
use TYPO3\CMS\Core\Site\SiteFinder;
use DateTimeImmutable;

final readonly class FrontendRenderer
{
    public function __construct(
        private SiteFinder $siteFinder,
        private PreviewTypoScriptContextBuilderInterface $contextBuilder,
        private ComponentPreviewRendererInterface $renderer,
        private AssetCollector $assets,
        private Context $context,
        private ListenerProvider $listenerProvider,
    ) {
    }

    public function render(string $identifier, string $siteIdentifier, string $hreflang): string
    {
        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (SiteNotFoundException $siteNotFoundException) {
            throw new RuntimeException(
                'Snapshot site "' . $siteIdentifier . '" was not found. Available sites: ' . implode(', ', array_keys($this->siteFinder->getAllSites())) . '.',
                6736937331,
                $siteNotFoundException,
            );
        }

        $language = array_find($site->getLanguages(), static fn($language): bool => $language->getHreflang() === $hreflang);
        if ($language === null) {
            $available = array_map(static fn(SiteLanguage $language): string => $language->getHreflang(), $site->getLanguages());
            throw new RuntimeException(
                'Snapshot language "' . $hreflang . '" was not found in site "' . $siteIdentifier . '". Available enabled hreflangs: ' . implode(', ', $available) . '.',
                6736937330,
            );
        }

        if ($site->invalidSets !== []) {
            $errors = array_map(
                static fn(array $set): string => $set['name'] . ': ' . $set['error']->value . ' (' . $set['context'] . ')',
                $site->invalidSets,
            );
            throw new RuntimeException('Invalid sets for snapshot site "' . $siteIdentifier . '": ' . implode('; ', $errors), 6736937332);
        }

        $uri = $language->getBase();
        if ($uri->getScheme() !== '' && !in_array($uri->getScheme(), ['https', 'http'], true)) {
            throw new RuntimeException('Snapshot site "' . $siteIdentifier . '", language "' . $hreflang . '" has an unsupported base URL scheme; configured base: "' . $uri . '".', 3531165321);
        }

        $oldRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $oldAssets = $this->assets->getState();
        $oldLanguage = $this->context->getAspect('language');
        $oldDate = $this->context->getAspect('date');
        $oldLocale = setlocale(LC_ALL, '0');
        try {
            $this->listenerProvider->addListener(GeneratePublicUrlForResourceEvent::class, PublicUrlPrefixer::class, 'prefixWithAbsRefPrefix');
            $this->assets->updateState(new AssetCollector()->getState());
            $this->context->setAspect('language', LanguageAspectFactory::createFromSiteLanguage($language));
            $this->context->setAspect('date', new DateTimeAspect(new DateTimeImmutable()));
            Locales::setSystemLocaleFromSiteLanguage($language);
            $request = new ServerRequest($uri)
                ->withAttribute('applicationType', 1)
                ->withAttribute('site', $site)
                ->withAttribute('language', $language)
                ->withAttribute('routing', new SiteRouteResult($uri, $site, $language, '/'));
            $GLOBALS['TYPO3_REQUEST'] = $request;
            $request = $this->contextBuilder->build($request);
            $GLOBALS['TYPO3_REQUEST'] = $request;
            return $this->renderer->renderVariant($identifier, $request);
        } finally {
            if ($oldRequest === null) {
                unset($GLOBALS['TYPO3_REQUEST']);
            } else {
                $GLOBALS['TYPO3_REQUEST'] = $oldRequest;
            }

            $this->assets->updateState($oldAssets);
            $this->context->setAspect('language', $oldLanguage);
            $this->context->setAspect('date', $oldDate);
            if ($oldLocale !== false) {
                setlocale(LC_ALL, $oldLocale);
            }
        }
    }
}
