<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Functional\Middleware;

use Andersundsehr\FrontendStudio\Command\SnapshotCommand;
use Andersundsehr\FrontendStudio\Controller\SnapshotController;
use Andersundsehr\FrontendStudio\Service\PreviewContextResolver;
use Andersundsehr\FrontendStudio\Service\Snapshot\Runner;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SnapshotEmptyCatalogTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor'];

    protected array $testExtensionsToLoad = [__DIR__ . '/../../..'];

    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/frontend_studio/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    public function testEmptyCatalogSucceedsInRunnerCliAndBackend(): void
    {
        $runner = $this->get(Runner::class);
        self::assertSame([], $runner->discover());
        self::assertSame(0, Runner::exitCode([]));
        $tester = new CommandTester(new SnapshotCommand($runner, $this->get(PreviewContextResolver::class)));
        self::assertSame(0, $tester->execute(['site' => 'preview', 'language' => 'de']));
        self::assertStringContainsString('0/0 passed.', $tester->getDisplay());

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/BackendUser.csv');
        $this->setUpBackendUser(1);
        $response = $this->get(SnapshotController::class)->runAction(
            new ServerRequest('/', 'POST')->withParsedBody([
                'operation' => 'discover', 'scope' => '', 'site' => 'preview', 'language' => 'de',
            ]),
        );
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(
            ['success' => true, 'identifiers' => [], 'catalog' => []],
            json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testExplicitUnknownScopeStillFailsWithAnEmptyCatalog(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(3349110734);
        $this->get(Runner::class)->discover('site:unknown');
    }
}
