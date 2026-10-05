<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Functional\Middleware;

use Andersundsehr\FrontendStudio\Service\Snapshot\Runner;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use RuntimeException;

final class SnapshotRunnerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        __DIR__ . '/../../..',
        __DIR__ . '/../Fixtures/Extensions/preview_site_set',
    ];

    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/frontend_studio/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    public function testCreatesThenComparesRealFixtureAndWrapperWithoutReplacingBaseline(): void
    {
        $runner = $this->get(Runner::class);
        self::assertSame(['site:wrappedCard:Default'], $runner->discover('site:wrappedCard'));
        $result = $runner->run('site:wrappedCard:Default', 'preview', 'de');
        try {
            self::assertSame('missing', $result['status'], $result['message']);
            self::assertStringContainsString('<section class="wrapper-example">', $result['expected']);
            self::assertStringContainsString('<article>Wrapped preview', $result['expected']);
            self::assertSame($result['expected'], file_get_contents($result['path']));
            self::assertSame('passed', $runner->run('site:wrappedCard:Default', 'preview', 'de')['status']);
            file_put_contents($result['path'], "changed\n");
            self::assertSame('failed', $runner->run('site:wrappedCard:Default', 'preview', 'de')['status']);
            self::assertSame("changed\n", file_get_contents($result['path']));
            self::assertSame('error', $runner->run('site:wrappedCard:Default', 'preview', 'unknown')['status']);
            self::assertSame('error', $runner->run('site:missingTransformer:Default', 'preview', 'de')['status']);
        } finally {
            if (is_file($result['path'])) {
                unlink($result['path']);
                rmdir(dirname($result['path']));
            }
        }
    }

    public function testUsesSiteTypoScriptAndSavedSlotsForMultipleVariants(): void
    {
        $runner = $this->get(Runner::class);
        $identifiers = $runner->discover('site:snapshotContext');
        self::assertCount(2, $identifiers);
        foreach ($identifiers as $identifier) {
            $result = $runner->run($identifier, 'preview', 'de');
            try {
                self::assertSame('missing', $result['status'], $result['message']);
                self::assertStringContainsString('german-site-context', $result['actual']);
                if ($identifier === 'site:snapshotContext:Default') {
                    self::assertStringContainsString('<strong>Saved slot', $result['actual']);
                }
            } finally {
                if (is_file($result['path'])) {
                    unlink($result['path']);
                    rmdir(dirname($result['path']));
                }
            }
        }
    }

    public function testUnknownScopeDoesNotPassAsZeroTests(): void
    {
        $this->expectException(RuntimeException::class);
        $this->get(Runner::class)->discover('site:missing:Default');
    }

    public function testInvalidFixtureIsReportedByDiscovery(): void
    {
        $fixture = __DIR__ . '/../Fixtures/Extensions/preview_site_set/Resources/Private/Components/Card/Card.fixture.yaml';
        $original = file_get_contents($fixture);
        try {
            file_put_contents($fixture, 'variants: [invalid');
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('site:card:');
            $this->get(Runner::class)->discover();
        } finally {
            file_put_contents($fixture, $original);
        }
    }
}
