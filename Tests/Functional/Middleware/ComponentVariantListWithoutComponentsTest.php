<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Functional\Middleware;

use Andersundsehr\FrontendStudio\Middleware\ComponentVariantListMiddleware;
use Andersundsehr\FrontendStudio\Service\ComponentMetadataProvider;
use Andersundsehr\FrontendStudio\Service\ComponentTreeDataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

#[CoversClass(ComponentVariantListMiddleware::class)]
final class ComponentVariantListWithoutComponentsTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor'];

    protected array $testExtensionsToLoad = [
        __DIR__ . '/../../..',
    ];

    public function testReturnsAnEmptyListWhenNoComponentsHaveFixtureVariants(): void
    {
        $middleware = new ComponentVariantListMiddleware(
            $this->get(ComponentTreeDataProvider::class),
            $this->get(ComponentMetadataProvider::class),
        );
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        $response = $middleware->process(
            new ServerRequest('https://example.test/__frontendStudio/variants'),
            $handler,
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['variants' => []], json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }
}
