<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Functional\Middleware;

use Andersundsehr\FrontendStudio\Service\ComponentFixtureProvider;
use Andersundsehr\FrontendStudio\Service\Snapshot\Runner;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Path;
use TYPO3\CMS\Core\Core\Environment;
use Andersundsehr\FrontendStudio\Service\Snapshot\Comparison;
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

    public function testCommandRendersAndComparesTextWithRelativeSiteAndLanguageBases(): void
    {
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('relative');
        self::assertSame('/en/', (string)$site->getDefaultLanguage()->getBase());
        $runner = $this->get(Runner::class);
        $tester = new CommandTester(new SnapshotCommand($runner));
        $arguments = ['site' => 'relative', 'language' => 'en-us', '--scope' => 'site:text'];
        $paths = [];
        foreach ($runner->discover('site:text') as $identifier) {
            $paths[] = __DIR__ . '/../Fixtures/Extensions/preview_site_set/Resources/Private/Components/Text/Text.fluid.html-snapshots/html-'
                . ComponentFixtureProvider::normalizeSlotFilenameSegment(explode(':', $identifier, 3)[2]) . '.html';
        }

        try {
            self::assertSame(2, $tester->execute($arguments));
            self::assertStringContainsString('WARNING (MISSING)', $tester->getDisplay());
            self::assertStringNotContainsString('Stack trace:', $tester->getDisplay());
            foreach ($paths as $path) {
                self::assertFileExists($path);
                $html = file_get_contents($path);
                self::assertNotFalse($html);
                self::assertStringContainsString('Federal Republic of Germany', $html);
                self::assertStringContainsString('id="c50"', $html);
                self::assertStringContainsString('<marquee>', $html);
            }

            self::assertSame(0, $tester->execute($arguments));
            self::assertStringContainsString('2/2 passed.', $tester->getDisplay());
        } finally {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            if (is_dir(dirname($paths[0]))) {
                rmdir(dirname($paths[0]));
            }
        }
    }

    public function testCommandReportsExceptionsWithTracesAndMismatchWarningsWithoutTraces(): void
    {
        $runner = $this->get(Runner::class);
        $tester = new CommandTester(new SnapshotCommand($runner));
        $arguments = ['site' => 'preview', 'language' => 'unknown', '--scope' => 'site:wrappedCard'];
        self::assertSame(1, $tester->execute($arguments));
        self::assertStringContainsString('RuntimeException:', $tester->getDisplay());
        self::assertStringNotContainsString('Stack trace:', $tester->getDisplay());
        self::assertStringNotContainsString('html-Default.html', $tester->getDisplay());
        self::assertSame(1, $tester->execute($arguments, ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]));
        $output = $tester->getDisplay();
        self::assertStringContainsString('Snapshot language "unknown" was not found in site "preview"', $output);
        self::assertStringContainsString('Available enabled hreflangs: de, en', $output);
        self::assertStringContainsString('RuntimeException:', $output);
        self::assertStringContainsString('Stack trace:', $output);

        $arguments['site'] = 'missing';
        self::assertSame(1, $tester->execute($arguments, ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]));
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
            self::assertStringContainsString('[-removed-] {+added+}', $tester->getDisplay());
            self::assertStringContainsString('To accept these changes, rerun this command with --update outside Production.', $tester->getDisplay());
            self::assertStringContainsString('Review and commit the updated snapshot files, then rerun without --update to verify.', $tester->getDisplay());
            self::assertStringNotContainsString('html-Default.html', $tester->getDisplay());
            self::assertStringNotContainsString('EXPECTED:', $tester->getDisplay());
            self::assertStringNotContainsString('ACTUAL:', $tester->getDisplay());
            self::assertStringNotContainsString('Stack trace:', $tester->getDisplay());
            self::assertStringNotContainsString('RuntimeException:', $tester->getDisplay());
            self::assertSame(1, $tester->execute($arguments, ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]));
            self::assertStringContainsString('To accept these changes, rerun this command with --update outside Production.', $tester->getDisplay());
            self::assertStringContainsString('html-Default.html', $tester->getDisplay());
        } finally {
            if (is_file($result['path'])) {
                unlink($result['path']);
                rmdir(dirname($result['path']));
            }
        }

        $arguments['--scope'] = 'site:unknown';
        self::assertSame(1, $tester->execute($arguments, ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]));
        self::assertStringContainsString('Stack trace:', $tester->getDisplay());
    }

    public function testCommandColorsLabelsAndOnlyShowsRelativePathsWhenVerbose(): void
    {
        $runner = $this->get(Runner::class);
        $tester = new CommandTester(new SnapshotCommand($runner));
        $arguments = ['site' => 'preview', 'language' => 'en', '--scope' => 'site:wrappedCard'];
        $path = __DIR__ . '/../Fixtures/Extensions/preview_site_set/Resources/Private/Components/WrappedCard/WrappedCard.html-snapshots/html-Default.html';
        try {
            self::assertSame(2, $tester->execute($arguments, ['decorated' => true]));
            $output = $tester->getDisplay();
            self::assertStringContainsString("\033[33;1mWARNING (MISSING)", $output);
            self::assertStringContainsString("\033[36msite:wrappedCard", $output);
            self::assertStringContainsString("\033[35mDefault", $output);
            self::assertStringNotContainsString('html-Default.html', $output);
            self::assertStringNotContainsString('dynamic markers', strtolower($output));
            self::assertStringNotContainsString('To accept this change', $output);

            self::assertSame(0, $tester->execute($arguments, ['decorated' => true]));
            self::assertStringContainsString("\033[32;1mPASSED", $tester->getDisplay());
            self::assertStringNotContainsString('To accept this change', $tester->getDisplay());
            self::assertStringNotContainsString('html-Default.html', $tester->getDisplay());
            self::assertSame(0, $tester->execute($arguments, ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]));
            $absolutePath = realpath($path);
            self::assertNotFalse($absolutePath);
            self::assertStringContainsString(Path::makeRelative($absolutePath, Environment::getProjectPath()), $tester->getDisplay());
            self::assertStringNotContainsString(Environment::getProjectPath() . '/', $tester->getDisplay());

            $baseline = file_get_contents($path);
            self::assertNotFalse($baseline);
            $spacedBaseline = str_replace(' ', " \t ", $baseline);
            file_put_contents($path, $spacedBaseline);
            self::assertSame(0, $tester->execute($arguments));
            self::assertSame($spacedBaseline, file_get_contents($path));
            self::assertStringNotContainsString('Dynamic markers used.', $tester->getDisplay());
            $lines = explode("\n", $baseline);
            $lines[1] = str_replace('Wrapped', Comparison::MARKER, $lines[1]);
            file_put_contents($path, implode("\n", $lines));
            self::assertSame(0, $tester->execute($arguments));
            self::assertStringContainsString('Dynamic markers used.', $tester->getDisplay());
            file_put_contents($path, str_replace('preview', 'broken', implode("\n", $lines)));
            self::assertSame(1, $tester->execute($arguments));
            self::assertStringContainsString('WARNING (MISMATCH)', $tester->getDisplay());
            self::assertStringContainsString('snapshot | actual', $tester->getDisplay());
            self::assertStringNotContainsString('{+Wrapped+}', $tester->getDisplay());

            $lines[0] = '<!-- frontend-studio:dynamic-line -->';
            file_put_contents($path, implode("\n", $lines));
            self::assertSame(1, $tester->execute($arguments));
            self::assertStringContainsString('Regenerate this snapshot with --update', $tester->getDisplay());
            self::assertSame(0, $tester->execute([...$arguments, '--update' => true]));
            self::assertSame($baseline, file_get_contents($path));
            self::assertSame(0, $tester->execute($arguments));
        } finally {
            if (is_file($path)) {
                unlink($path);
                rmdir(dirname($path));
            }
        }

        $arguments['language'] = 'unknown';
        self::assertSame(1, $tester->execute($arguments, ['decorated' => true]));
        self::assertStringContainsString("\033[31;1mERROR", $tester->getDisplay());
    }

    public function testCommandUpdatesOnlyScopedSnapshotsWithOneHintAfterAllMismatches(): void
    {
        $runner = $this->get(Runner::class);
        $tester = new CommandTester(new SnapshotCommand($runner));
        $arguments = ['site' => 'preview', 'language' => 'en', '--scope' => 'site:text'];
        $paths = [];
        try {
            foreach ([...$runner->discover('site:text'), 'site:wrappedCard:Default'] as $identifier) {
                $result = $runner->run($identifier, 'preview', 'en');
                $paths[] = $result['path'];
                self::assertSame('missing', $result['status'], $result['message']);
                file_put_contents($result['path'], "changed\n");
            }

            self::assertSame(1, $tester->execute($arguments));
            $output = $tester->getDisplay();
            self::assertSame(2, substr_count($output, 'WARNING (MISMATCH)'));
            self::assertSame(1, substr_count($output, 'To accept these changes'));
            self::assertGreaterThan(strrpos($output, '[-removed-]'), strpos($output, 'To accept these changes'));
            foreach ($paths as $path) {
                self::assertSame("changed\n", file_get_contents($path));
            }

            self::assertSame(0, $tester->execute([...$arguments, '-u' => true], ['decorated' => true]));
            self::assertSame(2, substr_count($tester->getDisplay(), "\033[32;1mUPDATED"));
            self::assertStringContainsString('2/2 snapshots ready (2 updated).', $tester->getDisplay());
            self::assertStringNotContainsString('To accept these changes', $tester->getDisplay());
            self::assertStringNotContainsString('html-Simple Test.html', $tester->getDisplay());
            self::assertSame("changed\n", file_get_contents($paths[2]));
            self::assertSame(0, $tester->execute($arguments));
            self::assertStringContainsString('2/2 passed.', $tester->getDisplay());

            self::assertSame(0, $tester->execute([...$arguments, '--update' => true]));
            self::assertStringContainsString('2/2 snapshots ready (0 updated).', $tester->getDisplay());
            $baseline = file_get_contents($paths[0]);
            self::assertSame(1, $tester->execute([...$arguments, 'language' => 'unknown', '--update' => true]));
            self::assertSame($baseline, file_get_contents($paths[0]));

            unlink($paths[0]);
            self::assertSame(0, $tester->execute([...$arguments, '--update' => true]));
            self::assertStringContainsString('2/2 snapshots ready (1 updated).', $tester->getDisplay());
            self::assertSame($baseline, file_get_contents($paths[0]));
        } finally {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            foreach (array_unique(array_map(dirname(...), $paths)) as $directory) {
                if (is_dir($directory)) {
                    rmdir($directory);
                }
            }
        }
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
