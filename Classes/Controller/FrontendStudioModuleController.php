<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Controller;

use Andersundsehr\FrontendStudio\Service\ComponentMetadataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use Throwable;

#[AsController]
final readonly class FrontendStudioModuleController
{
    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ComponentMetadataProvider $componentMetadataProvider,
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
        $moduleTemplate->assignMultiple($this->getVariantAssignments($selectedVariantIdentifier));

        return $moduleTemplate->renderResponse('FrontendStudio/Variant');
    }

    /**
     * @return array<string, mixed>
     */
    private function getVariantAssignments(string $selectedVariantIdentifier): array
    {
        $selectedComponentMetadata = $this->componentMetadataProvider->getComponentMetadataForVariantIdentifier($selectedVariantIdentifier);

        return [
            'selectedVariantIdentifier' => $selectedVariantIdentifier,
            'selectedComponentMetadata' => $selectedComponentMetadata,
            'componentPreviewUri' => $selectedComponentMetadata !== null
                ? $this->buildComponentPreviewUri($selectedVariantIdentifier)
                : null,
        ];
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
