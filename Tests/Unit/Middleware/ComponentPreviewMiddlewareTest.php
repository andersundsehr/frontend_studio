<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Middleware;

use Andersundsehr\FrontendStudio\Middleware\ComponentPreviewContextMiddleware;
use Andersundsehr\FrontendStudio\Middleware\ComponentPreviewMiddleware;
use Andersundsehr\FrontendStudio\Service\ComponentMetadataProvider;
use Andersundsehr\FrontendStudio\Service\ComponentPathResolverInterface;
use Andersundsehr\FrontendStudio\Service\ComponentPreviewRendererInterface;
use Andersundsehr\FrontendStudio\Service\FluidUsageSnippetRenderer;
use Andersundsehr\FrontendStudio\Service\HtmlSourceHighlighter;
use Andersundsehr\FrontendStudio\Service\PreviewAssetRenderer;
use Andersundsehr\FrontendStudio\Service\PreviewTypoScriptContextBuilderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\ServerRequest;

#[CoversClass(ComponentPreviewMiddleware::class)]
final class ComponentPreviewMiddlewareTest extends TestCase
{
    public function testLegacyQueryMarkerDoesNotTriggerPreviewRendering(): void
    {
        $request = new ServerRequest('https://example.test/?frontendStudioComponentPreview=1')
            ->withQueryParams(['frontendStudioComponentPreview' => '1']);
        $contextBuilder = $this->createMock(PreviewTypoScriptContextBuilderInterface::class);
        $contextBuilder->expects(self::never())->method('build');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::identicalTo($request))
            ->willReturn(new HtmlResponse('normal response'));

        $response = $this->createMiddleware(
            $this->createMock(ComponentPreviewRendererInterface::class),
            contextBuilder: $contextBuilder,
        )
            ->process($request, $handler);

        self::assertSame('normal response', (string)$response->getBody());
    }

    /**
     * @param array<string, string>|null $expectedSlotOverrides
     */
    #[DataProvider('slotOverridesDataProvider')]
    public function testForwardsSlotOverrides(?string $encodedSlots, ?array $expectedSlotOverrides): void
    {
        $queryParams = [
            'frontendStudioPreviewFormat' => 'fragment',
            'componentVariant' => 'site:Card:Default',
        ];
        if ($encodedSlots !== null) {
            $queryParams['componentVariantSlots'] = $encodedSlots;
        }

        $request = $this->createPreviewRequest($queryParams);
        $renderer = $this->createMock(ComponentPreviewRendererInterface::class);
        $renderer->expects(self::once())
            ->method('renderVariant')
            ->with('site:Card:Default', self::identicalTo($request), null, $expectedSlotOverrides)
            ->willReturn('<article>Preview</article>');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $this->createMiddleware($renderer)->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('<article>Preview</article>', (string)$response->getBody());
    }

    public function testPassesTheCompiledRequestToTheRendererAndUpdatesTheGlobalRequest(): void
    {
        $request = $this->createPreviewRequest([
            'frontendStudioPreviewFormat' => 'fragment',
            'componentVariant' => 'site:Card:Default',
        ]);
        $compiledRequest = $request->withAttribute('frontend.typoscript', 'compiled');
        $contextBuilder = $this->createMock(PreviewTypoScriptContextBuilderInterface::class);
        $contextBuilder->expects(self::once())
            ->method('build')
            ->with(self::identicalTo($request))
            ->willReturn($compiledRequest);
        $renderer = $this->createMock(ComponentPreviewRendererInterface::class);
        $renderer->expects(self::once())
            ->method('renderVariant')
            ->with('site:Card:Default', self::identicalTo($compiledRequest), null, null)
            ->willReturn('<article>Preview</article>');

        $response = $this->createMiddleware($renderer, contextBuilder: $contextBuilder)
            ->process($request, $this->createMock(RequestHandlerInterface::class));

        self::assertSame($compiledRequest, $GLOBALS['TYPO3_REQUEST']);
        self::assertSame('<article>Preview</article>', (string)$response->getBody());
    }

    public function testResolvesVariantNameAndComponentPathBeforeRendering(): void
    {
        $request = $this->createPreviewRequest([
            'frontendStudioPreviewFormat' => 'fragment',
            'componentVariantName' => 'Special Variant',
            'componentPath' => '/packages/content_element_text/Components/Element/Text/Text.spec.ts',
        ]);
        $pathResolver = $this->createMock(ComponentPathResolverInterface::class);
        $pathResolver->expects(self::once())
            ->method('findVariantIdentifiers')
            ->with('/packages/content_element_text/Components/Element/Text/Text.spec.ts', 'Special Variant')
            ->willReturn(['site:element.text:Special Variant']);
        $renderer = $this->createMock(ComponentPreviewRendererInterface::class);
        $renderer->expects(self::once())
            ->method('renderVariant')
            ->with('site:element.text:Special Variant', self::identicalTo($request), null, null)
            ->willReturn('<article>Preview</article>');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $this->createMiddleware($renderer, $pathResolver)->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testRejectsMixedVariantSelectorFormats(): void
    {
        $request = $this->createPreviewRequest([
            'frontendStudioPreviewFormat' => 'fragment',
            'componentVariant' => 'site:Card:Default',
            'componentVariantName' => 'Default',
            'componentPath' => '/components/Card.spec.ts',
        ]);
        $pathResolver = $this->createMock(ComponentPathResolverInterface::class);
        $pathResolver->expects(self::never())->method('findVariantIdentifiers');
        $renderer = $this->createMock(ComponentPreviewRendererInterface::class);
        $renderer->expects(self::never())->method('renderVariant');
        $handler = $this->createMock(RequestHandlerInterface::class);

        $response = $this->createMiddleware($renderer, $pathResolver)->process($request, $handler);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testRejectsIncompleteVariantNameAndComponentPathPair(): void
    {
        $request = $this->createPreviewRequest([
            'frontendStudioPreviewFormat' => 'fragment',
            'componentVariantName' => 'Default',
        ]);
        $pathResolver = $this->createMock(ComponentPathResolverInterface::class);
        $pathResolver->expects(self::never())->method('findVariantIdentifiers');
        $renderer = $this->createMock(ComponentPreviewRendererInterface::class);
        $renderer->expects(self::never())->method('renderVariant');
        $handler = $this->createMock(RequestHandlerInterface::class);

        $response = $this->createMiddleware($renderer, $pathResolver)->process($request, $handler);

        self::assertSame(400, $response->getStatusCode());
    }

    /**
     * @param list<string> $matches
     */
    #[DataProvider('componentPathMatchDataProvider')]
    public function testReturnsAnErrorWhenComponentPathDoesNotResolveUniquely(array $matches, int $expectedStatusCode): void
    {
        $request = $this->createPreviewRequest([
            'frontendStudioPreviewFormat' => 'fragment',
            'componentVariantName' => 'Default',
            'componentPath' => '/components/Card.spec.ts',
        ]);
        $pathResolver = $this->createMock(ComponentPathResolverInterface::class);
        $pathResolver->expects(self::once())
            ->method('findVariantIdentifiers')
            ->with('/components/Card.spec.ts', 'Default')
            ->willReturn($matches);
        $renderer = $this->createMock(ComponentPreviewRendererInterface::class);
        $renderer->expects(self::never())->method('renderVariant');
        $handler = $this->createMock(RequestHandlerInterface::class);

        $response = $this->createMiddleware($renderer, $pathResolver)->process($request, $handler);

        self::assertSame($expectedStatusCode, $response->getStatusCode());
    }

    /**
     * @return iterable<string, array{list<string>, int}>
     */
    public static function componentPathMatchDataProvider(): iterable
    {
        yield 'no component match' => [[], 404];
        yield 'multiple component matches' => [['site:Card:Default', 'other:Card:Default'], 409];
    }

    /**
     * @return iterable<string, array{string|null, array<string, string>|null}>
     */
    public static function slotOverridesDataProvider(): iterable
    {
        yield 'valid slot map' => ['{"default":"<strong>Override</strong>","footer":""}', ['default' => '<strong>Override</strong>', 'footer' => '']];
        yield 'missing slot map' => [null, null];
        yield 'empty slot map' => ['', null];
        yield 'invalid JSON' => ['{', null];
        yield 'scalar JSON' => ['"slot"', null];
        yield 'list JSON' => ['["slot"]', null];
        yield 'numeric slot name' => ['{"0":"slot"}', null];
        yield 'null slot content' => ['{"default":null}', null];
        yield 'boolean slot content' => ['{"default":true}', null];
        yield 'numeric slot content' => ['{"default":1}', null];
        yield 'array slot content' => ['{"default":[]}', null];
    }

    /** @param array<string, mixed> $queryParams */
    private function createPreviewRequest(array $queryParams): ServerRequest
    {
        return new ServerRequest('https://example.test/')
            ->withQueryParams($queryParams)
            ->withAttribute(ComponentPreviewContextMiddleware::PREVIEW_ATTRIBUTE, true);
    }

    private function createMiddleware(
        ComponentPreviewRendererInterface $renderer,
        ?ComponentPathResolverInterface $componentPathResolver = null,
        ?PreviewTypoScriptContextBuilderInterface $contextBuilder = null,
    ): ComponentPreviewMiddleware {
        if ($contextBuilder === null) {
            $contextBuilderStub = $this->createStub(PreviewTypoScriptContextBuilderInterface::class);
            $contextBuilderStub->method('build')->willReturnCallback(static fn ($request) => $request);
            $contextBuilder = $contextBuilderStub;
        }

        return new ComponentPreviewMiddleware(
            $renderer,
            $this->createUninitialized(ComponentMetadataProvider::class),
            $componentPathResolver ?? $this->createStub(ComponentPathResolverInterface::class),
            $this->createUninitialized(FluidUsageSnippetRenderer::class),
            $this->createUninitialized(HtmlSourceHighlighter::class),
            $this->createUninitialized(PreviewAssetRenderer::class),
            new ListenerProvider($this->createStub(ContainerInterface::class)),
            $contextBuilder,
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $className
     * @return T
     */
    private function createUninitialized(string $className): object
    {
        return new ReflectionClass($className)->newInstanceWithoutConstructor();
    }
}
