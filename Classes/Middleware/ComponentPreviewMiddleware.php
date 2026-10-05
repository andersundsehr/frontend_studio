<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Middleware;

use InvalidArgumentException;
use RuntimeException;
use JsonException;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantValues;
use Andersundsehr\FrontendStudio\Service\ComponentMetadataProvider;
use Andersundsehr\FrontendStudio\Service\ComponentPathResolverInterface;
use Andersundsehr\FrontendStudio\Service\ComponentPreviewRendererInterface;
use Andersundsehr\FrontendStudio\Service\FluidUsageSnippetRenderer;
use Andersundsehr\FrontendStudio\Service\HtmlSourceHighlighter;
use Andersundsehr\FrontendStudio\Service\PreviewAssetRenderer;
use Andersundsehr\FrontendStudio\Service\PreviewTypoScriptContextBuilderInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Resource\Event\GeneratePublicUrlForResourceEvent;
use TYPO3\CMS\Frontend\Resource\PublicUrlPrefixer;

final readonly class ComponentPreviewMiddleware implements MiddlewareInterface
{
    private const string PREVIEW_FORMAT_PARAMETER = 'frontendStudioPreviewFormat';

    private const string PREVIEW_FORMAT_FRAGMENT = 'fragment';

    private const string PREVIEW_FORMAT_HIGHLIGHTED_FRAGMENT = 'highlighted-fragment';

    private const string PREVIEW_FORMAT_FLUID_USAGE = 'fluid-usage';

    private const string VARIANT_VALUES_PARAMETER = 'componentVariantValues';

    private const string VARIANT_SLOTS_PARAMETER = 'componentVariantSlots';

    private const string VARIANT_NAME_PARAMETER = 'componentVariantName';

    private const string COMPONENT_PATH_PARAMETER = 'componentPath';

    public function __construct(
        private ComponentPreviewRendererInterface $componentPreviewRenderer,
        private ComponentMetadataProvider $componentMetadataProvider,
        private ComponentPathResolverInterface $componentPathResolver,
        private FluidUsageSnippetRenderer $fluidUsageSnippetRenderer,
        private HtmlSourceHighlighter $htmlSourceHighlighter,
        private PreviewAssetRenderer $previewAssetRenderer,
        private ListenerProvider $listenerProvider,
        private PreviewTypoScriptContextBuilderInterface $previewTypoScriptContextBuilder,
        private Context $context = new Context(),
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->isPreviewRequest($request)) {
            return $handler->handle($request);
        }

        $GLOBALS['TYPO3_REQUEST'] = $request;
        $this->listenerProvider->addListener(
            GeneratePublicUrlForResourceEvent::class,
            PublicUrlPrefixer::class,
            'prefixWithAbsRefPrefix',
        );

        $queryParams = $request->getQueryParams();
        $isFragmentRequest = $this->isFragmentRequest($queryParams) || $this->isHighlightedFragmentRequest($queryParams);
        if (
            (array_key_exists(self::VARIANT_VALUES_PARAMETER, $queryParams) || array_key_exists(self::VARIANT_SLOTS_PARAMETER, $queryParams))
            && !$this->context->getPropertyFromAspect('backend.user', 'isLoggedIn', false)
        ) {
            return $this->createErrorResponse('Backend login required', 'Preview overrides require an authenticated backend session.', 403, $isFragmentRequest);
        }

        $hasLegacyVariant = array_key_exists('componentVariant', $queryParams);
        $hasVariantName = array_key_exists(self::VARIANT_NAME_PARAMETER, $queryParams);
        $hasComponentPath = array_key_exists(self::COMPONENT_PATH_PARAMETER, $queryParams);

        if ($hasLegacyVariant && ($hasVariantName || $hasComponentPath)) {
            return $this->createErrorResponse('Invalid component preview request', 'Use either componentVariant or componentVariantName with componentPath.', 400, $isFragmentRequest);
        }

        if ($hasLegacyVariant) {
            if (!is_string($queryParams['componentVariant'])) {
                return $this->createErrorResponse('Invalid component preview request', 'The componentVariant parameter must be a string.', 400, $isFragmentRequest);
            }

            $variantIdentifier = $queryParams['componentVariant'];
        } elseif ($hasVariantName && $hasComponentPath) {
            $variantName = $queryParams[self::VARIANT_NAME_PARAMETER];
            $componentPath = $queryParams[self::COMPONENT_PATH_PARAMETER];
            if (!is_string($variantName) || $variantName === '' || !is_string($componentPath) || $componentPath === '') {
                return $this->createErrorResponse('Invalid component preview request', 'componentVariantName and componentPath must be non-empty strings.', 400, $isFragmentRequest);
            }

            try {
                $variantIdentifiers = $this->componentPathResolver->findVariantIdentifiers($componentPath, $variantName);
            } catch (InvalidArgumentException $exception) {
                return $this->createErrorResponse('Invalid component path', $exception->getMessage(), 400, $isFragmentRequest);
            } catch (Throwable $throwable) {
                return $this->createErrorResponse('Component path resolution failed', $throwable->getMessage(), 500, $isFragmentRequest);
            }

            if ($variantIdentifiers === []) {
                return $this->createErrorResponse('Component not found', 'No registered component matches the supplied componentPath.', 404, $isFragmentRequest);
            }

            if (count($variantIdentifiers) > 1) {
                return $this->createErrorResponse('Component path is ambiguous', 'More than one registered component matches the supplied componentPath.', 409, $isFragmentRequest);
            }

            $variantIdentifier = $variantIdentifiers[0];
        } else {
            return $this->createErrorResponse('Invalid component preview request', 'Provide componentVariant or both componentVariantName and componentPath.', 400, $isFragmentRequest);
        }

        $variantValueOverrides = $this->getVariantValueOverrides($queryParams);
        $slotOverrides = $this->getSlotOverrides($queryParams);

        if ($this->isFluidUsageRequest($queryParams)) {
            return new JsonResponse(
                $this->fluidUsageSnippetRenderer->render(
                    $this->componentMetadataProvider->getComponentMetadataForVariantIdentifier($variantIdentifier),
                    $variantValueOverrides,
                    $slotOverrides,
                ),
                200,
                ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow'],
            );
        }

        try {
            $request = $this->previewTypoScriptContextBuilder->build($request);
        } catch (Throwable $throwable) {
            return $this->createErrorResponse('Component variant rendering failed', $throwable->getMessage(), 500, $isFragmentRequest);
        }

        $GLOBALS['TYPO3_REQUEST'] = $request;

        try {
            $content = $this->componentPreviewRenderer->renderVariant($variantIdentifier, $request, $variantValueOverrides, $slotOverrides);
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
        return $request->getAttribute(ComponentPreviewContextMiddleware::PREVIEW_ATTRIBUTE) === true;
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

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, string>|null
     */
    private function getSlotOverrides(array $queryParams): ?array
    {
        $encodedSlots = $queryParams[self::VARIANT_SLOTS_PARAMETER] ?? null;
        if (!is_string($encodedSlots) || $encodedSlots === '') {
            return null;
        }

        try {
            $decodedSlots = json_decode($encodedSlots, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decodedSlots)) {
            return null;
        }

        $slots = [];
        foreach ($decodedSlots as $name => $content) {
            if (!is_string($name) || !is_string($content)) {
                return null;
            }

            $slots[$name] = $content;
        }

        return $slots;
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

    background: linear-gradient(45deg, rgba(0, 0, 0, 0.098) 25%, transparent 25%, transparent 75%, rgba(0, 0, 0, 0.098) 75%, rgba(0, 0, 0, 0.098) 0), linear-gradient(45deg, rgba(0, 0, 0, 0.098) 25%, transparent 25%, transparent 75%, rgba(0, 0, 0, 0.098) 75%, rgba(0, 0, 0, 0.098) 0), white;
    background-repeat: repeat, repeat;
    background-position: 0 0, 5px 5px;
    background-clip: border-box, border-box;
    background-size: 10px 10px, 10px 10px;
    transition: none;
    transform: scaleX(1) scaleY(1) scaleZ(1);
}
@layer defaults {
    :where(#rendered-component > *) {
        background: white;
    }
}
EOF;
        $body .= $this->previewAssetRenderer->renderAssets();
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>'
            . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</title><style>' . $css . '</style></head><body><div id="rendered-component">'
            . $body
            . '</div></body></html>';
    }
}
