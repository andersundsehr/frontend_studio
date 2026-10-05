<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Controller;

use Andersundsehr\FrontendStudio\Middleware\ComponentPreviewContextMiddleware;
use Andersundsehr\FrontendStudio\Middleware\ComponentPreviewMiddleware;
use Andersundsehr\FrontendStudio\Service\PreviewContextResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Site\SiteFinder;

#[AsController]
final readonly class ComponentPreviewController
{
    public function __construct(
        private ComponentPreviewMiddleware $previewMiddleware,
        private PreviewContextResolver $contextResolver,
        private SiteFinder $siteFinder,
        private Context $context,
    ) {
    }

    public function renderAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->context->getPropertyFromAspect('backend.user', 'isLoggedIn', false)) {
            return new JsonResponse(['message' => 'A backend login is required.'], 403);
        }

        $query = $request->getQueryParams();
        if (!in_array($query['frontendStudioPreviewFormat'] ?? null, ['fragment', 'highlighted-fragment', 'fluid-usage'], true)) {
            return new JsonResponse(['message' => 'Select a supported inspector preview format.'], 400);
        }

        if (isset($query['site']) && !is_string($query['site']) || isset($query['language']) && !is_string($query['language'])) {
            return new JsonResponse(['message' => 'Invalid preview site or language.'], 400);
        }

        $resolved = $this->contextResolver->resolve($request, $query['site'] ?? null, $query['language'] ?? null);
        if ($resolved === null) {
            return new JsonResponse(['message' => 'Preview site or language not found.'], 404);
        }

        [$siteIdentifier, $hreflang] = $resolved;
        $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        $language = array_find($site->getLanguages(), static fn($language): bool => $language->getHreflang() === $hreflang);
        if (($query['site'] ?? $siteIdentifier) !== $siteIdentifier || ($query['language'] ?? $hreflang) !== $hreflang) {
            return new JsonResponse(['message' => 'Preview site or language not found.'], 404);
        }

        if ($language === null) {
            return new JsonResponse(['message' => 'Preview language not found.'], 404);
        }

        $previewRequest = $request
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
            ->withAttribute(ComponentPreviewContextMiddleware::PREVIEW_ATTRIBUTE, true)
            ->withAttribute('site', $site)
            ->withAttribute('language', $language)
            ->withUri($language->getBase()->withQuery($request->getUri()->getQuery()));
        return $this->previewMiddleware->process($previewRequest, new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new JsonResponse(['message' => 'The inspector preview could not be rendered.'], 400);
            }
        });
    }
}
