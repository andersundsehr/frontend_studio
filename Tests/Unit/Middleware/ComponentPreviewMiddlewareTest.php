<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Middleware;

use Andersundsehr\FrontendStudio\Middleware\ComponentPreviewMiddleware;
use Andersundsehr\FrontendStudio\Service\ComponentMetadataProvider;
use Andersundsehr\FrontendStudio\Service\ComponentPreviewRendererInterface;
use Andersundsehr\FrontendStudio\Service\FluidUsageSnippetRenderer;
use Andersundsehr\FrontendStudio\Service\HtmlSourceHighlighter;
use Andersundsehr\FrontendStudio\Service\PreviewAssetRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Http\ServerRequest;

#[CoversClass(ComponentPreviewMiddleware::class)]
final class ComponentPreviewMiddlewareTest extends TestCase
{
    /**
     * @param array<string, string>|null $expectedSlotOverrides
     */
    #[DataProvider('slotOverridesDataProvider')]
    public function testForwardsSlotOverrides(?string $encodedSlots, ?array $expectedSlotOverrides): void
    {
        $queryParams = [
            'frontendStudioComponentPreview' => '1',
            'frontendStudioPreviewFormat' => 'fragment',
            'componentVariant' => 'site:Card:Default',
        ];
        if ($encodedSlots !== null) {
            $queryParams['componentVariantSlots'] = $encodedSlots;
        }

        $request = new ServerRequest('https://example.test/')->withQueryParams($queryParams);
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

    private function createMiddleware(ComponentPreviewRendererInterface $renderer): ComponentPreviewMiddleware
    {
        return new ComponentPreviewMiddleware(
            $renderer,
            $this->createUninitialized(ComponentMetadataProvider::class),
            $this->createUninitialized(FluidUsageSnippetRenderer::class),
            $this->createUninitialized(HtmlSourceHighlighter::class),
            $this->createUninitialized(PreviewAssetRenderer::class),
            new ListenerProvider($this->createStub(ContainerInterface::class)),
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
