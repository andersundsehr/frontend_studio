<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Middleware;

use InvalidArgumentException;
use RuntimeException;
use JsonException;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantValues;
use Andersundsehr\FrontendStudio\Service\ComponentMetadataProvider;
use Andersundsehr\FrontendStudio\Service\ComponentPreviewRenderer;
use Andersundsehr\FrontendStudio\Service\FluidUsageSnippetRenderer;
use Andersundsehr\FrontendStudio\Service\HtmlSourceHighlighter;
use Andersundsehr\FrontendStudio\Service\PreviewAssetRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use TYPO3\CMS\Core\Http\HtmlResponse;

final readonly class ComponentPreviewMiddleware implements MiddlewareInterface
{
    private const string PREVIEW_PARAMETER = 'frontendStudioComponentPreview';

    private const string PREVIEW_FORMAT_PARAMETER = 'frontendStudioPreviewFormat';

    private const string PREVIEW_FORMAT_FRAGMENT = 'fragment';

    private const string PREVIEW_FORMAT_HIGHLIGHTED_FRAGMENT = 'highlighted-fragment';

    private const string PREVIEW_FORMAT_FLUID_USAGE = 'fluid-usage';

    private const string VARIANT_VALUES_PARAMETER = 'componentVariantValues';

    public function __construct(
        private ComponentPreviewRenderer $componentPreviewRenderer,
        private ComponentMetadataProvider $componentMetadataProvider,
        private FluidUsageSnippetRenderer $fluidUsageSnippetRenderer,
        private HtmlSourceHighlighter $htmlSourceHighlighter,
        private PreviewAssetRenderer $previewAssetRenderer,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->isPreviewRequest($request)) {
            return $handler->handle($request);
        }

        $GLOBALS['TYPO3_REQUEST'] = $request;

        $queryParams = $request->getQueryParams();
        $variantIdentifier = isset($queryParams['componentVariant']) ? (string)$queryParams['componentVariant'] : '';
        $variantValueOverrides = $this->getVariantValueOverrides($queryParams);
        $isFragmentRequest = $this->isFragmentRequest($queryParams) || $this->isHighlightedFragmentRequest($queryParams);

        if ($this->isFluidUsageRequest($queryParams)) {
            return $this->createHtmlResponse(
                $this->fluidUsageSnippetRenderer->render(
                    $this->componentMetadataProvider->getComponentMetadataForVariantIdentifier($variantIdentifier),
                    $variantValueOverrides,
                ),
            );
        }

        try {
            $content = $this->componentPreviewRenderer->renderVariant($variantIdentifier, $request, $variantValueOverrides);
        } catch (InvalidArgumentException $exception) {
            return $this->createErrorResponse('Invalid component variant', $exception->getMessage(), 400, $isFragmentRequest);
        } catch (RuntimeException $exception) {
            return $this->createErrorResponse('Component variant not found', $exception->getMessage(), 404, $isFragmentRequest);
        } catch (Throwable $throwable) {
            return $this->createErrorResponse('Component variant rendering failed', $throwable->getMessage(), 500, $isFragmentRequest);
        }

        if ($isFragmentRequest) {
            if ($this->isHighlightedFragmentRequest($queryParams)) {
                return $this->createHtmlResponse($this->htmlSourceHighlighter->highlight($content));
            }

            return $this->createHtmlResponse($content);
        }

        return $this->createHtmlResponse($this->wrapHtml('Component preview', $content));
    }

    private function isPreviewRequest(ServerRequestInterface $request): bool
    {
        return ($request->getQueryParams()[self::PREVIEW_PARAMETER] ?? null) === '1';
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    private function isFragmentRequest(array $queryParams): bool
    {
        return ($queryParams[self::PREVIEW_FORMAT_PARAMETER] ?? null) === self::PREVIEW_FORMAT_FRAGMENT;
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    private function isHighlightedFragmentRequest(array $queryParams): bool
    {
        return ($queryParams[self::PREVIEW_FORMAT_PARAMETER] ?? null) === self::PREVIEW_FORMAT_HIGHLIGHTED_FRAGMENT;
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    private function isFluidUsageRequest(array $queryParams): bool
    {
        return ($queryParams[self::PREVIEW_FORMAT_PARAMETER] ?? null) === self::PREVIEW_FORMAT_FLUID_USAGE;
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    private function getVariantValueOverrides(array $queryParams): ?ComponentVariantValues
    {
        $encodedValues = $queryParams[self::VARIANT_VALUES_PARAMETER] ?? null;
        if (!is_string($encodedValues) || $encodedValues === '') {
            return null;
        }

        try {
            $decodedValues = json_decode($encodedValues, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decodedValues)) {
            return null;
        }

        return ComponentVariantValues::fromSubmittedValues($decodedValues);
    }

    private function createErrorResponse(string $title, string $message, int $status, bool $isFragmentRequest): HtmlResponse
    {
        $body = '<h1>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1><p>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';

        return $this->createHtmlResponse($isFragmentRequest ? $body : $this->wrapHtml($title, $body), $status);
    }

    private function createHtmlResponse(string $html, int $status = 200): HtmlResponse
    {
        return new HtmlResponse($html, $status, [
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    private function wrapHtml(string $title, string $body): string
    {
        $css = <<<'EOF'
html, body {
    margin: 0;
}
body {
    margin: 5px;

    background: linear-gradient(45deg, rgba(0, 0, 0, 0.0980392) 25%, transparent 25%, transparent 75%, rgba(0, 0, 0, 0.0980392) 75%, rgba(0, 0, 0, 0.0980392) 0), linear-gradient(45deg, rgba(0, 0, 0, 0.0980392) 25%, transparent 25%, transparent 75%, rgba(0, 0, 0, 0.0980392) 75%, rgba(0, 0, 0, 0.0980392) 0), white;
    background-repeat: repeat, repeat;
    background-position: 0 0, 5px 5px;
    background-clip: border-box, border-box;
    background-size: 10px 10px, 10px 10px;
    transition: none;
    transform: scaleX(1) scaleY(1) scaleZ(1);
}
@layer defaults {
    :where(body > *) {
        background: white;
    }
}
EOF;
        $body .= $this->previewAssetRenderer->renderAssets();
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>'
            . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</title><style>' . $css . '</style></head><body>'
            . $body
            . '</body></html>';
    }
}
