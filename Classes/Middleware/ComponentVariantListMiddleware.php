<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Middleware;

use Andersundsehr\FrontendStudio\Service\ComponentMetadataProvider;
use Andersundsehr\FrontendStudio\Service\ComponentTreeDataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

final readonly class ComponentVariantListMiddleware implements MiddlewareInterface
{
    private const string VARIANTS_PATH = '/__frontendStudio/variants';

    public function __construct(
        private ComponentTreeDataProvider $componentTreeDataProvider,
        private ComponentMetadataProvider $componentMetadataProvider,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() !== self::VARIANTS_PATH || $request->getMethod() !== 'GET') {
            return $handler->handle($request);
        }

        $variants = [];
        foreach ($this->componentTreeDataProvider->getTreeNodes() as $node) {
            $variantIdentifier = $node['identifier'] ?? null;
            if (($node['nodeType'] ?? null) !== 'variant' || !is_string($variantIdentifier)) {
                continue;
            }

            $metadata = $this->componentMetadataProvider->getComponentMetadataForVariantIdentifier($variantIdentifier);
            if ($metadata === null) {
                continue;
            }

            $variants[] = [
                'url' => '/__frontendStudio/preview?' . http_build_query(
                    ['componentVariant' => $variantIdentifier],
                    '',
                    '&',
                    PHP_QUERY_RFC3986,
                ),
                'componentName' => $metadata->componentIdentifier,
                'phpNamespace' => $metadata->sourceNamespace,
                'variantName' => $metadata->variantName,
            ];
        }

        return new JsonResponse(['variants' => $variants], 200, [
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
