<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Middleware;

use Andersundsehr\FrontendStudio\Service\PreviewContextResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

final readonly class ComponentPreviewContextMiddleware implements MiddlewareInterface
{
    public const string PREVIEW_ATTRIBUTE = 'frontendStudio.previewRequest';

    private const string PREVIEW_PATH = '/__frontendStudio/preview';

    private const string SITE_PARAMETER = 'site';

    private const string LANGUAGE_PARAMETER = 'language';

    public function __construct(
        private SiteFinder $siteFinder,
        private PreviewContextResolver $previewContextResolver,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() !== self::PREVIEW_PATH) {
            return $handler->handle($request);
        }

        $queryParams = $request->getQueryParams();
        $siteIdentifier = $queryParams[self::SITE_PARAMETER] ?? null;
        $languageHreflang = $queryParams[self::LANGUAGE_PARAMETER] ?? null;
        if (
            ($siteIdentifier !== null && (!is_string($siteIdentifier) || $siteIdentifier === ''))
            || ($languageHreflang !== null && (!is_string($languageHreflang) || $languageHreflang === ''))
        ) {
            return $this->createContextErrorResponse('Invalid preview site or language.', 400);
        }

        $resolvedContext = $this->previewContextResolver->resolve(
            $request,
            is_string($siteIdentifier) ? $siteIdentifier : null,
            is_string($languageHreflang) ? $languageHreflang : null,
        );
        if ($resolvedContext === null) {
            return $this->createContextErrorResponse('Preview site not found.', 404);
        }

        [$selectedSiteIdentifier, $selectedLanguageHreflang] = $resolvedContext;
        if ($siteIdentifier !== null && $siteIdentifier !== $selectedSiteIdentifier) {
            return $this->createContextErrorResponse('Preview site not found.', 404);
        }
        if ($languageHreflang !== null && $languageHreflang !== $selectedLanguageHreflang) {
            return $this->createContextErrorResponse('Preview language not found for the selected site.', 404);
        }

        try {
            $site = $this->siteFinder->getSiteByIdentifier($selectedSiteIdentifier);
        } catch (SiteNotFoundException) {
            return $this->createContextErrorResponse('Preview site not found.', 404);
        }

        $language = null;
        foreach ($site->getLanguages() as $siteLanguage) {
            if ($siteLanguage->getHreflang() === $selectedLanguageHreflang) {
                $language = $siteLanguage;
                break;
            }
        }
        if (!($language instanceof SiteLanguage)) {
            return $this->createContextErrorResponse('Preview language not found for the selected site.', 404);
        }

        $targetUri = $language->getBase();
        $requestUri = $request->getUri();
        if ($targetUri->getScheme() === '') {
            $targetUri = $targetUri->withScheme($requestUri->getScheme());
        }
        if ($targetUri->getHost() === '') {
            $targetUri = $targetUri
                ->withHost($requestUri->getHost())
                ->withPort($requestUri->getPort());
        }
        $targetUri = $targetUri
            ->withQuery($requestUri->getQuery())
            ->withFragment('');

        return $handler->handle(
            $request
                ->withAttribute(self::PREVIEW_ATTRIBUTE, true)
                ->withUri($targetUri),
        );
    }

    private function createContextErrorResponse(string $message, int $statusCode): HtmlResponse
    {
        return new HtmlResponse('<h1>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>', $statusCode, [
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
