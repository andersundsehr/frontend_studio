<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

final readonly class PreviewContextResolver
{
    public function __construct(private SiteFinder $siteFinder)
    {
    }

    /**
     * @return array{string, string}|null
     */
    public function resolve(
        ServerRequestInterface $request,
        ?string $siteIdentifier = null,
        ?string $languageHreflang = null,
    ): ?array {
        $sites = array_values($this->siteFinder->getAllSites());
        if ($sites === []) {
            return null;
        }

        $site = $this->findSite($sites, $siteIdentifier) ?? $this->findSiteByRequestOrigin($sites, $request) ?? $sites[0];
        $languages = array_values($site->getLanguages());
        $language = $this->findLanguage($site, $languageHreflang) ?? $languages[0] ?? null;

        return [$site->getIdentifier(), $language instanceof SiteLanguage ? $language->getHreflang() : ''];
    }

    /**
     * @param list<Site> $sites
     */
    private function findSite(array $sites, ?string $siteIdentifier): ?Site
    {
        if ($siteIdentifier === null) {
            return null;
        }

        foreach ($sites as $site) {
            if ($site->getIdentifier() === $siteIdentifier) {
                return $site;
            }
        }

        return null;
    }

    /**
     * @param list<Site> $sites
     */
    private function findSiteByRequestOrigin(array $sites, ServerRequestInterface $request): ?Site
    {
        $requestUri = $request->getUri();
        $requestOrigin = $this->buildOrigin($requestUri->getScheme(), $requestUri->getHost(), $requestUri->getPort());
        if ($requestOrigin === null) {
            return null;
        }

        foreach ($sites as $site) {
            if ($this->getUrlOrigin((string)$site->getDefaultLanguage()->getBase()) === $requestOrigin) {
                return $site;
            }
        }

        return null;
    }

    private function findLanguage(Site $site, ?string $languageHreflang): ?SiteLanguage
    {
        if ($languageHreflang === null) {
            return null;
        }

        foreach ($site->getLanguages() as $language) {
            if ($language->getHreflang() === $languageHreflang) {
                return $language;
            }
        }

        return null;
    }

    private function getUrlOrigin(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = isset($parts['scheme']) ? (string)$parts['scheme'] : '';
        $host = isset($parts['host']) ? (string)$parts['host'] : '';
        $port = isset($parts['port']) ? $parts['port'] : null;

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
