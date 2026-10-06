<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Functional\Middleware;

use Andersundsehr\FrontendStudio\Service\Snapshot\Runner;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use RuntimeException;
use Andersundsehr\FrontendStudio\Command\SnapshotCommand;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Site\Set\SetError;

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
            self::assertStringEndsWith('WrappedCard.html-snapshots/html-Default.html', $result['path']);
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

    public function testCollidingVariantNamesWithoutSlotsNeverReadOrCreateABaseline(): void
    {
        $fixture = __DIR__ . '/../Fixtures/Extensions/preview_site_set/Resources/Private/Components/WrappedCard/WrappedCard.fixture.yaml';
        $original = file_get_contents($fixture);
        try {
            file_put_contents($fixture, "variants:\n  a/b:\n    title: First\n  a-b:\n    title: Second\n");
            $runner = $this->get(Runner::class);
            foreach (['site:wrappedCard:a/b', 'site:wrappedCard:a-b'] as $identifier) {
                $result = $runner->run($identifier, 'preview', 'de');
                self::assertSame('error', $result['status']);
                self::assertSame('Fixture variants use the same snapshot filename.', $result['message']);
                self::assertFileDoesNotExist($result['path']);
            }
        } finally {
            file_put_contents($fixture, $original);
        }
    }

    public function testRendersTextWithTranslationsStringableWrapperAndSpecialVariantNames(): void
    {
        $runner = $this->get(Runner::class);
        $identifiers = $runner->discover('site:text');
        self::assertCount(2, $identifiers);
        foreach ($identifiers as $identifier) {
            $result = $runner->run($identifier, 'preview', 'en');
            try {
                self::assertSame('missing', $result['status'], $result['message']);
                self::assertStringContainsString('<marquee>', $result['actual']);
                self::assertStringContainsString('id="c50"', $result['actual']);
                self::assertStringContainsString('Federal Republic of Germany', $result['actual']);
                self::assertStringContainsString('Test', $result['actual']);
                self::assertSame('passed', $runner->run($identifier, 'preview', 'en')['status']);
            } finally {
                if (is_file($result['path'])) {
                    unlink($result['path']);
                    rmdir(dirname($result['path']));
                }
            }
        }
    }

    public function testCommandReportsExceptionsWithTracesAndMismatchWarningsWithoutTraces(): void
    {
        $runner = $this->get(Runner::class);
        $tester = new CommandTester(new SnapshotCommand($runner));
        $arguments = ['site' => 'preview', 'language' => 'unknown', '--scope' => 'site:wrappedCard'];
        self::assertSame(1, $tester->execute($arguments));
        $output = $tester->getDisplay();
        self::assertStringContainsString('Snapshot language "unknown" was not found in site "preview"', $output);
        self::assertStringContainsString('Available enabled hreflangs: de, en', $output);
        self::assertStringContainsString('RuntimeException:', $output);
        self::assertStringContainsString('Stack trace:', $output);

        $arguments['site'] = 'missing';
        self::assertSame(1, $tester->execute($arguments));
        self::assertStringContainsString('Snapshot site "missing" was not found. Available sites: preview', $tester->getDisplay());
        self::assertStringContainsString('SiteNotFoundException:', $tester->getDisplay());
        self::assertStringContainsString('Next RuntimeException:', $tester->getDisplay());

        $arguments['site'] = 'preview';
        $arguments['language'] = 'en';
        $result = $runner->run('site:wrappedCard:Default', 'preview', 'en');
        try {
            self::assertSame('missing', $result['status'], $result['message']);
            file_put_contents($result['path'], "changed\n");
            self::assertSame(1, $tester->execute($arguments));
            self::assertStringContainsString('WARNING (MISMATCH)', $tester->getDisplay());
            self::assertStringNotContainsString('Stack trace:', $tester->getDisplay());
            self::assertStringNotContainsString('RuntimeException:', $tester->getDisplay());
        } finally {
            if (is_file($result['path'])) {
                unlink($result['path']);
                rmdir(dirname($result['path']));
            }
        }

        $arguments['--scope'] = 'site:unknown';
        self::assertSame(1, $tester->execute($arguments));
        self::assertStringContainsString('Stack trace:', $tester->getDisplay());
    }

    public function testReportsInvalidSetNameReasonAndContext(): void
    {
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('preview');
        $site->invalidSets = ['acme/broken' => ['name' => 'acme/broken', 'error' => SetError::missingDependency, 'context' => 'acme/missing']];

        $result = $this->get(Runner::class)->run('site:wrappedCard:Default', 'preview', 'en');
        self::assertSame('error', $result['status']);
        self::assertStringContainsString('Invalid sets for snapshot site "preview": acme/broken: missing-dependency (acme/missing)', $result['message']);
        self::assertInstanceOf(RuntimeException::class, $result['exception']);
        self::assertFileDoesNotExist($result['path']);
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
