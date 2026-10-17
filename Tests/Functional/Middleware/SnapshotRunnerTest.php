<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Functional\Middleware;

use ReflectionProperty;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use Andersundsehr\FrontendStudio\Service\ComponentPreviewRendererInterface;
use Andersundsehr\FrontendStudio\Service\PreviewTypoScriptContextBuilderInterface;
use TYPO3\CMS\Core\Page\AssetCollector;
use Andersundsehr\FrontendStudio\Service\PreviewContextResolver;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Andersundsehr\FrontendStudio\Service\Snapshot\FrontendRenderer;
use DateTimeImmutable;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Resource\Event\GeneratePublicUrlForResourceEvent;
use TYPO3\CMS\Backend\Resource\PublicUrlPrefixer as BackendPublicUrlPrefixer;
use Andersundsehr\FrontendStudio\Service\Snapshot\SamplingClock;
use Andersundsehr\FrontendStudio\Service\Snapshot\HtmlFormatter;
use TYPO3\CMS\Core\Core\ApplicationContext;

final class SnapshotRunnerTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['rte_ckeditor'];

    protected array $testExtensionsToLoad = [
        __DIR__ . '/../../..',
        __DIR__ . '/../Fixtures/Extensions/preview_site_set',
    ];

    protected array $pathsToLinkInTestInstance = [
        'typo3conf/ext/frontend_studio/Tests/Functional/Fixtures/Sites' => 'typo3conf/sites',
    ];

    #[DataProvider('resourceUrlContexts')]
    public function testRendersImagesAndIconsWithNormalizedFrontendRequest(string $site, string $language, bool $absolute, string $prefix): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['frontend_studio']['showOwnComponents'] = '1';
        $template = __DIR__ . '/../Fixtures/Extensions/preview_site_set/Resources/Private/Components/WrappedCard/WrappedCard.fluid.html';
        $setup = __DIR__ . '/../Fixtures/Extensions/preview_site_set/Configuration/Sets/PreviewTest/setup.typoscript';
        $originalTemplate = file_get_contents($template);
        $originalSetup = file_get_contents($setup);
        self::assertNotFalse($originalTemplate);
        self::assertNotFalse($originalSetup);
        $oldRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        try {
            $this->replaceTemplate($template, $originalTemplate . '<f:image src="EXT:frontend_studio/Resources/Public/Image/FrontendStudioDeveloper.png" alt="Developer" width="100" />'
                . '<core:icon identifier="actions-chevron-down" />');
            file_put_contents($setup, $originalSetup . "\nconfig.absRefPrefix = auto\nconfig.forceAbsoluteUrls = " . (int)$absolute . "\n");
            $html = $this->get(FrontendRenderer::class)->render('site:wrappedCard:Default', $site, $language);
            self::assertStringContainsString('src="' . $prefix . 'typo3temp/assets/_processed_/', $html);
            self::assertStringContainsString('alt="Developer"', $html);
            $listeners = $this->get(ListenerProvider::class);
            $listeners->addListener(GeneratePublicUrlForResourceEvent::class, BackendPublicUrlPrefixer::class, 'prefixWithSitePath');
            $backendDefinition = $listeners->getAllListenerDefinitions()[GeneratePublicUrlForResourceEvent::class][BackendPublicUrlPrefixer::class];
            $backendHtml = $this->get(FrontendRenderer::class)->render('site:wrappedCard:Default', $site, $language);
            self::assertSame($html, $backendHtml, 'Backend snapshots must use the same resource URLs as CLI snapshots.');
            self::assertSame($backendDefinition, $listeners->getAllListenerDefinitions()[GeneratePublicUrlForResourceEvent::class][BackendPublicUrlPrefixer::class]);
            self::assertStringContainsString('href="' . $prefix, $html);
            self::assertStringContainsString('<svg', $html);
            $valueField = $this->get(FrontendRenderer::class)->render('frontend.studio:variant.valueField:Fallback', $site, $language);
            self::assertStringContainsString('<textarea', $valueField);
            self::assertStringContainsString('data-fixture-type="array"', $valueField);
            self::assertSame($oldRequest, $GLOBALS['TYPO3_REQUEST'] ?? null);
        } finally {
            $this->replaceTemplate($template, $originalTemplate);
            file_put_contents($setup, $originalSetup);
        }
    }

    public function testBackendResourceUrlListenerIsRestoredAfterRenderingFailure(): void
    {
        $listeners = $this->get(ListenerProvider::class);
        $listeners->addListener(GeneratePublicUrlForResourceEvent::class, BackendPublicUrlPrefixer::class, 'prefixWithSitePath');

        $definitions = $listeners->getAllListenerDefinitions();
        $renderer = $this->createMock(ComponentPreviewRendererInterface::class);
        $renderer->method('renderVariant')->willThrowException(new RuntimeException('Rendering failed.'));
        $frontend = new FrontendRenderer(
            $this->get(SiteFinder::class),
            $this->get(PreviewTypoScriptContextBuilderInterface::class),
            $renderer,
            $this->get(AssetCollector::class),
            $this->get(Context::class),
            $listeners,
            $this->get(UriBuilder::class),
        );
        try {
            $frontend->render('site:wrappedCard:Default', 'preview', 'en');
            self::fail('The rendering failure must propagate.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('Rendering failed.', $runtimeException->getMessage());
        }

        self::assertSame($definitions, $listeners->getAllListenerDefinitions());
    }

    /** @return iterable<string, array{string, string, bool, string}> */
    public static function resourceUrlContexts(): iterable
    {
        yield 'absolute site with automatic relative prefix' => ['preview', 'en', false, '/'];
        yield 'relative site and language with automatic relative prefix' => ['relative', 'en-us', false, '/'];
        yield 'absolute site with forced absolute URLs' => ['preview', 'en', true, 'https://preview.test/'];
    }

    #[DataProvider('missingSnapshotContexts')]
    public function testMissingSnapshotsAreReadOnlyUntilExplicitlyCreated(string $context, bool $readOnly): void
    {
        $property = new ReflectionProperty(Environment::class, 'context');
        $original = Environment::getContext();
        $path = '';
        try {
            $property->setValue(null, new ApplicationContext($context));
            $runner = $this->get(Runner::class);
            $tester = new CommandTester(new SnapshotCommand($runner, new PreviewContextResolver($this->get(SiteFinder::class))));
            $arguments = ['site' => 'preview', 'language' => 'de', '--scope' => 'site:wrappedCard:Default'];
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                $result = $runner->run('site:wrappedCard:Default', 'preview', 'de');
                $path = $result['path'];
                self::assertSame('missing', $result['status'], $result['message']);
                self::assertSame('', $result['expected']);
                self::assertStringContainsString('Wrapped preview', $result['actual']);
                self::assertNull($result['exception']);
                self::assertSame(2, $tester->execute($arguments));
                self::assertStringContainsString('WARNING (MISSING)', $tester->getDisplay());
                self::assertStringContainsString(Path::makeRelative($path, Environment::getProjectPath()), $tester->getDisplay());
                self::assertStringContainsString('To create missing snapshots, rerun this command with --update', $tester->getDisplay());
                self::assertStringNotContainsString('Dynamic markers used.', $tester->getDisplay());
                self::assertFileDoesNotExist($path);
                self::assertDirectoryDoesNotExist(dirname($path));
            }

            self::assertSame($readOnly ? 1 : 2, $tester->execute([...$arguments, '-u' => true]));
            if ($readOnly) {
                self::assertStringContainsString('ERROR', $tester->getDisplay());
                self::assertFileDoesNotExist($path);
                self::assertDirectoryDoesNotExist(dirname($path));
            } else {
                self::assertStringContainsString('CREATED site:wrappedCard:Default:', $tester->getDisplay());
                self::assertSame(0, $tester->execute($arguments));
            }
        } finally {
            $property->setValue(null, $original);
            if (is_file($path)) {
                unlink($path);
                rmdir(dirname($path));
            }
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function missingSnapshotContexts(): iterable
    {
        yield 'Development' => ['Development', false];
        yield 'Testing' => ['Testing', false];
        yield 'Production' => ['Production', true];
        yield 'Production subcontext' => ['Production/Staging', true];
    }

    public function testExplicitlyCreatesThenComparesRealFixtureAndWrapperWithoutReplacingBaseline(): void
    {
        $runner = $this->get(Runner::class);
        self::assertSame(['site:wrappedCard:Default'], $runner->discover('site:wrappedCard'));
        $result = $runner->run('site:wrappedCard:Default', 'preview', 'de', true);
        try {
            self::assertSame('created', $result['status'], $result['message']);
            self::assertStringEndsWith('WrappedCard.fluid.html-snapshots/html-Default@preview@de.snapshot.html', $result['path']);
            self::assertStringContainsString('<section class="wrapper-example">', $result['expected']);
            self::assertStringContainsString("<article>\n    Wrapped preview\n  </article>", $result['expected']);
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

    #[DataProvider('inlineWhitespaceTemplates')]
    public function testInlineWhitespaceChangesFailRunnerAndCliUntilUpdated(string $before, string $after): void
    {
        $template = __DIR__ . '/../Fixtures/Extensions/preview_site_set/Resources/Private/Components/WrappedCard/WrappedCard.fluid.html';
        $original = file_get_contents($template);
        self::assertNotFalse($original);
        $path = $template . '-snapshots/html-Default@preview@en.snapshot.html';
        try {
            $this->replaceTemplate($template, $original . $before);
            $runner = $this->get(Runner::class);
            $created = $runner->run('site:wrappedCard:Default', 'preview', 'en', true);
            self::assertSame('created', $created['status'], $created['message']);
            self::assertStringStartsWith(HtmlFormatter::HEADER, $created['expected']);
            self::assertSame('passed', $runner->run('site:wrappedCard:Default', 'preview', 'en')['status']);
            $this->replaceTemplate($template, $original . $after);
            $failed = $runner->run('site:wrappedCard:Default', 'preview', 'en');
            self::assertSame('failed', $failed['status'], $failed['message']);
            self::assertNull($failed['exception']);
            self::assertSame($created['expected'], file_get_contents($path));
            $tester = new CommandTester(new SnapshotCommand($runner, new PreviewContextResolver($this->get(SiteFinder::class))));
            $arguments = ['site' => 'preview', 'language' => 'en', '--scope' => 'site:wrappedCard:Default'];
            self::assertSame(1, $tester->execute($arguments));
            self::assertStringContainsString('WARNING (MISMATCH)', $tester->getDisplay());
            self::assertMatchesRegularExpression('/^- [ 0-9]+ \| [ -]+ /m', $tester->getDisplay());
            self::assertMatchesRegularExpression('/^\+ [ -]+ \| [ 0-9]+ /m', $tester->getDisplay());
            self::assertSame(2, $tester->execute([...$arguments, '-u' => true]));
            self::assertSame(0, $tester->execute($arguments));
        } finally {
            $this->replaceTemplate($template, $original);
            if (is_file($path)) {
                unlink($path);
                rmdir(dirname($path));
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function inlineWhitespaceTemplates(): iterable
    {
        foreach (
            [
                'text before inline tag' => ['<p>Hello<strong>world</strong></p>', '<p>Hello <strong>world</strong></p>'],
                'adjacent inline tags' => ['<strong>Hello</strong><em>world</em>', '<strong>Hello</strong> <em>world</em>'],
                'leading paragraph space' => ['<p>Hello</p>', '<p> Hello</p>'],
                'trailing paragraph space' => ['<p>Hello</p>', '<p>Hello </p>'],
                'leading container space' => ['<div>Hello</div>', '<div> Hello</div>'],
                'trailing container space' => ['<div>Hello</div>', '<div>Hello </div>'],
            ] as $name => [$withoutSpace, $withSpace]
        ) {
            yield $name . ' adds whitespace' => [$withoutSpace, $withSpace];
            yield $name . ' removes whitespace' => [$withSpace, $withoutSpace];
        }
    }

    #[DataProvider('dynamicDateFormats')]
    public function testDateViewHelperCreatesOneInlineMarkerWithStableSurroundingText(string $format): void
    {
        $template = __DIR__ . '/../Fixtures/Extensions/preview_site_set/Resources/Private/Components/WrappedCard/WrappedCard.fluid.html';
        $original = file_get_contents($template);
        self::assertNotFalse($original);
        $path = $template . '-snapshots/html-Default@preview@en.snapshot.html';
        try {
            $this->replaceTemplate($template, $original . '<p>A<f:format.date date="now" format="' . $format . '" />B</p>');
            $runner = $this->get(Runner::class);
            $tester = new CommandTester(new SnapshotCommand($runner, new PreviewContextResolver($this->get(SiteFinder::class))));
            $arguments = ['site' => 'preview', 'language' => 'en', '--scope' => 'site:wrappedCard:Default'];
            self::assertSame(2, $tester->execute($arguments));
            self::assertStringNotContainsString('Dynamic markers used.', $tester->getDisplay());
            self::assertFileDoesNotExist($path);
            self::assertSame(2, $tester->execute([...$arguments, '-u' => true]));
            self::assertStringContainsString('CREATED site:wrappedCard:Default:', $tester->getDisplay());
            self::assertStringContainsString('Dynamic markers used.', $tester->getDisplay());
            $result = $runner->run('site:wrappedCard:Default', 'preview', 'en');
            self::assertSame('passed', $result['status'], $result['message']);
            self::assertSame('', $result['message']);
            self::assertStringContainsString('<p>A' . Comparison::MARKER . 'B', $result['expected']);
            self::assertSame(1, substr_count($result['expected'], Comparison::MARKER));
            self::assertSame(0, $tester->execute($arguments));
            self::assertSame("PASSED site:wrappedCard:Default\n1/1 passed.\n", $tester->getDisplay());
            self::assertSame(0, $tester->execute([...$arguments, '-u' => true]));
            self::assertSame("PASSED site:wrappedCard:Default\n1/1 snapshots ready (0 updated).\n", $tester->getDisplay());
            file_put_contents($path, str_replace('B', 'C', $result['expected']));
            self::assertSame(1, $tester->execute($arguments));
            self::assertStringNotContainsString('Dynamic markers used.', $tester->getDisplay());
            self::assertSame(2, $tester->execute([...$arguments, '-u' => true]));
            self::assertStringContainsString('UPDATED site:wrappedCard:Default:', $tester->getDisplay());
            self::assertStringContainsString('Dynamic markers used.', $tester->getDisplay());
            self::assertSame($result['expected'], file_get_contents($path));
        } finally {
            $this->replaceTemplate($template, $original);
            if (is_file($path)) {
                unlink($path);
                rmdir(dirname($path));
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function dynamicDateFormats(): iterable
    {
        yield 'named date and time' => ['Y-M-D H:m:s.u'];
        yield 'date without time' => ['Y-m-d'];
        yield 'time without seconds' => ['H:i'];
        yield 'year alone' => ['Y'];
    }

    #[DataProvider('changedDynamicTemplates')]
    public function testMismatchDiffMasksDynamicValuesAfterMarkupChanges(string $before, string $after, string $maskedAddition): void
    {
        $template = __DIR__ . '/../Fixtures/Extensions/preview_site_set/Resources/Private/Components/WrappedCard/WrappedCard.fluid.html';
        $original = file_get_contents($template);
        self::assertNotFalse($original);
        $path = $template . '-snapshots/html-Default@preview@en.snapshot.html';
        try {
            $this->replaceTemplate($template, $original . $before);
            $runner = $this->get(Runner::class);
            $created = $runner->run('site:wrappedCard:Default', 'preview', 'en', true);
            self::assertSame('created', $created['status'], $created['message']);
            $this->replaceTemplate($template, $original . $after);
            $result = $runner->run('site:wrappedCard:Default', 'preview', 'en');
            self::assertSame('failed', $result['status'], $result['message']);
            self::assertNull($result['exception']);
            self::assertSame($created['expected'], $result['expected']);
            self::assertSame($created['expected'], file_get_contents($path));
            self::assertStringContainsString($maskedAddition, $result['actual']);
            self::assertDoesNotMatchRegularExpression('/\d{4}-[A-Z][a-z]{2}-[A-Z][a-z]{2} \d{2}:\d{2}:\d{2}/', $result['actual']);

            $tester = new CommandTester(new SnapshotCommand($runner, new PreviewContextResolver($this->get(SiteFinder::class))));
            self::assertSame(1, $tester->execute(['site' => 'preview', 'language' => 'en', '--scope' => 'site:wrappedCard']));
            $output = $tester->getDisplay();
            self::assertStringContainsString('WARNING (MISMATCH)', $output);
            self::assertStringNotContainsString('Dynamic markers used.', $output);
            self::assertStringContainsString(Path::makeRelative($path, Environment::getProjectPath()), $output);
            self::assertStringNotContainsString(Environment::getProjectPath() . '/', $output);
            self::assertMatchesRegularExpression('/^\+ .*' . preg_quote($maskedAddition, '/') . '/m', $output);
            self::assertDoesNotMatchRegularExpression('/\d{4}-[A-Z][a-z]{2}-[A-Z][a-z]{2} \d{2}:\d{2}:\d{2}/', $output);
            self::assertStringNotContainsString('Stack trace:', $output);
        } finally {
            $this->replaceTemplate($template, $original);
            if (is_file($path)) {
                unlink($path);
                rmdir(dirname($path));
            }
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function changedDynamicTemplates(): iterable
    {
        $date = '<f:format.date date="now" format="Y-M-D H:i:s.u" />';
        yield 'line break before the date' => ['<div>TestA' . $date . 'B</div>', '<div>Test<br>A' . $date . 'B</div>', 'A' . Comparison::MARKER . 'B'];
        yield 'new tag around the date' => ['<p>A' . $date . 'B</p>', '<p><time>A' . $date . 'B</time></p>', '<time>A' . Comparison::MARKER . 'B'];
        yield 'first dynamic value introduced in an attribute' => ['<p>Stable</p>', '<p title="A' . $date . 'B">Stable</p>', 'title="A' . Comparison::MARKER . 'B"'];
        yield 'stable label changes beside the date' => ['<p>Old A' . $date . 'B</p>', '<p>New A' . $date . 'B</p>', 'New A' . Comparison::MARKER . 'B'];
    }

    #[DataProvider('savedDateSnapshots')]
    public function testNormalChecksCompareBothClockSamplesWithoutChangingBaseline(bool $dynamic): void
    {
        $template = __DIR__ . '/../Fixtures/Extensions/preview_site_set/Resources/Private/Components/WrappedCard/WrappedCard.fluid.html';
        $original = file_get_contents($template);
        self::assertNotFalse($original);
        $path = $template . '-snapshots/html-Default@preview@en.snapshot.html';
        try {
            $this->replaceTemplate($template, $original . '<p>Year: <f:format.date date="now" format="Y" /></p>');
            $date = new DateTimeImmutable();
            $renderer = $this->get(FrontendRenderer::class);
            $formatter = new HtmlFormatter();
            $first = $formatter->format($renderer->render('site:wrappedCard:Default', 'preview', 'en', $date));
            $second = $formatter->format($renderer->render('site:wrappedCard:Default', 'preview', 'en', SamplingClock::advance($date)));
            $baseline = $dynamic ? new Comparison()->create($first, $second) : $first;
            mkdir(dirname($path));
            file_put_contents($path, $baseline);
            $result = $this->get(Runner::class)->run('site:wrappedCard:Default', 'preview', 'en');
            self::assertSame($dynamic ? 'passed' : 'failed', $result['status'], $result['message']);
            self::assertSame($baseline, $result['expected']);
            self::assertSame($baseline, file_get_contents($path));
            self::assertNull($result['exception']);
            if (!$dynamic) {
                self::assertStringContainsString('Year: ' . Comparison::MARKER, $result['actual']);
                self::assertNotSame($first, $result['actual']);
            }
        } finally {
            $this->replaceTemplate($template, $original);
            if (is_file($path)) {
                unlink($path);
                rmdir(dirname($path));
            }
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function savedDateSnapshots(): iterable
    {
        yield 'current datetime matches but changed datetime fails' => [false];
        yield 'reviewed marker matches both datetime samples' => [true];
    }

    #[DataProvider('clockDependentMarkupBaselines')]
    public function testClockDependentMarkupRemainsAMismatchWithTheFailingSample(bool $baselineMatchesFirst): void
    {
        $template = __DIR__ . '/../Fixtures/Extensions/preview_site_set/Resources/Private/Components/WrappedCard/WrappedCard.fluid.html';
        $original = file_get_contents($template);
        self::assertNotFalse($original);
        $path = $template . '-snapshots/html-Default@preview@en.snapshot.html';
        try {
            $date = new DateTimeImmutable();
            $this->replaceTemplate($template, $original . '<f:if condition="{f:format.date(date: \'now\', format: \'Y\')} == ' . $date->format('Y') . '">'
                . '<f:then><p>Current</p></f:then><f:else><div>Changed<br>Structure</div></f:else></f:if>');
            $renderer = $this->get(FrontendRenderer::class);
            $formatter = new HtmlFormatter();
            $first = $formatter->format($renderer->render('site:wrappedCard:Default', 'preview', 'en', $date));
            $second = $formatter->format($renderer->render('site:wrappedCard:Default', 'preview', 'en', SamplingClock::advance($date)));
            self::assertStringContainsString('<p>Current</p>', $first);
            self::assertStringContainsString("Changed\n", $second);
            self::assertStringContainsString('<br>', $second);
            $baseline = $baselineMatchesFirst ? $first : $second;
            mkdir(dirname($path));
            file_put_contents($path, $baseline);
            $result = $this->get(Runner::class)->run('site:wrappedCard:Default', 'preview', 'en');
            self::assertSame('failed', $result['status'], $result['message']);
            self::assertNull($result['exception']);
            self::assertSame($baselineMatchesFirst ? $second : $first, $result['actual']);
            self::assertSame($baseline, file_get_contents($path));
        } finally {
            $this->replaceTemplate($template, $original);
            if (is_file($path)) {
                unlink($path);
                rmdir(dirname($path));
            }
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function clockDependentMarkupBaselines(): iterable
    {
        yield 'changed clock sample differs from baseline' => [true];
        yield 'current clock sample differs from baseline' => [false];
    }

    #[DataProvider('dateClockFormats')]
    public function testDateViewHelperUsesExplicitClockAndRestoresContext(string $format, string $firstDate, string $secondDate): void
    {
        $template = __DIR__ . '/../Fixtures/Extensions/preview_site_set/Resources/Private/Components/WrappedCard/WrappedCard.fluid.html';
        $original = file_get_contents($template);
        self::assertNotFalse($original);
        $context = $this->get(Context::class);
        $oldDate = $context->getAspect('date');
        try {
            $this->replaceTemplate($template, $original . '<p>A<f:format.date date="now" format="' . $format . '" />B</p>');
            $renderer = $this->get(FrontendRenderer::class);
            $date = new DateTimeImmutable('2026-10-07T14:10:01');
            self::assertStringContainsString('<p>A' . $firstDate . 'B</p>', $renderer->render('site:wrappedCard:Default', 'preview', 'en', $date));
            self::assertSame($oldDate, $context->getAspect('date'));
            self::assertStringContainsString('<p>A' . $secondDate . 'B</p>', $renderer->render('site:wrappedCard:Default', 'preview', 'en', SamplingClock::advance($date)));
            self::assertSame($oldDate, $context->getAspect('date'));
        } finally {
            $this->replaceTemplate($template, $original);
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function dateClockFormats(): iterable
    {
        yield 'named date and time' => ['Y-M-D H:m:s.u', '2026-Oct-Wed 14:10:01.000000', '2027-Nov-Mon 15:11:02.000000'];
        yield 'ISO date and time' => ['Y-m-d\\TH:i:s', '2026-10-07T14:10:01', '2027-11-08T15:11:02'];
        yield 'seconds' => ['H:i:s', '14:10:01', '15:11:02'];
        yield 'date without seconds changes' => ['Y-m-d', '2026-10-07', '2027-11-08'];
        yield 'minutes without seconds change' => ['H:i', '14:10', '15:11'];
    }

    public function testDateContextIsRestoredAfterRenderingException(): void
    {
        $context = $this->get(Context::class);
        $oldDate = $context->getAspect('date');
        try {
            $this->get(FrontendRenderer::class)->render('site:wrappedCard:missing', 'preview', 'en', new DateTimeImmutable('2030-01-01'));
            self::fail('A missing variant must fail to render.');
        } catch (RuntimeException) {
            self::assertSame($oldDate, $context->getAspect('date'));
        }
    }

    public function testInlineDebugOutputDoesNotChangeStructureBetweenSamples(): void
    {
        $template = __DIR__ . '/../Fixtures/Extensions/preview_site_set/Resources/Private/Components/WrappedCard/WrappedCard.fluid.html';
        $original = file_get_contents($template);
        self::assertNotFalse($original);
        $path = $template . '-snapshots/html-Default@preview@en.snapshot.html';
        try {
            $this->replaceTemplate($template, '<f:argument name="uri" type="TYPO3\\CMS\\Core\\Http\\Uri" />'
                . '<f:argument name="link" type="TYPO3\\CMS\\Core\\LinkHandling\\TypolinkParameter" />'
                . '<f:argument name="image" type="TYPO3\\CMS\\Core\\Resource\\File" />'
                . $original . '<f:image image="{image}" /><f:debug inline="{true}">{uri}</f:debug>{uri}'
                . '<f:debug inline="{true}">{link}</f:debug><f:link.typolink parameter="{link}" />');
            $runner = $this->get(Runner::class);
            $result = $runner->run('site:wrappedCard:Default', 'preview', 'en', true);
            self::assertSame('created', $result['status'], $result['message']);
            self::assertStringContainsString('extbase-debugger-inline', $result['expected']);
            self::assertStringNotContainsString('<style', $result['expected']);
            self::assertStringContainsString('<img', $result['expected']);
            self::assertStringContainsString('<a', $result['expected']);
            self::assertSame('passed', $runner->run('site:wrappedCard:Default', 'preview', 'en')['status']);
        } finally {
            $this->replaceTemplate($template, $original);
            if (is_file($path)) {
                unlink($path);
                rmdir(dirname($path));
            }
        }
    }

    public function testUsesSiteTypoScriptAndSavedSlotsForMultipleVariants(): void
    {
        $runner = $this->get(Runner::class);
        $identifiers = $runner->discover('site:snapshotContext');
        self::assertCount(2, $identifiers);
        foreach ($identifiers as $identifier) {
            $result = $runner->run($identifier, 'preview', 'de', true);
            try {
                self::assertSame('created', $result['status'], $result['message']);
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

    public function testSimilarVariantNamesUseIndependentBaselines(): void
    {
        $fixture = __DIR__ . '/../Fixtures/Extensions/preview_site_set/Resources/Private/Components/WrappedCard/WrappedCard.fixture.yaml';
        $original = file_get_contents($fixture);
        $paths = [];
        try {
            file_put_contents($fixture, "variants:\n  a/b:\n    title: First\n  a-b:\n    title: Second\n");
            $runner = $this->get(Runner::class);
            foreach (['a/b' => 'First', 'a-b' => 'Second'] as $variant => $title) {
                $identifier = 'site:wrappedCard:' . $variant;
                $result = $runner->run($identifier, 'preview', 'de', true);
                $paths[] = $result['path'];
                self::assertSame('created', $result['status'], $result['message']);
                self::assertStringContainsString($title, $result['expected']);
                self::assertSame($result['expected'], file_get_contents($result['path']));
                self::assertSame('passed', $runner->run($identifier, 'preview', 'de')['status']);
            }

            self::assertNotSame($paths[0], $paths[1]);
            $first = file_get_contents($paths[0]);
            $second = file_get_contents($paths[1]);
            self::assertNotFalse($first);
            self::assertNotFalse($second);
            self::assertStringContainsString('First', $first);
            self::assertStringContainsString('Second', $second);
        } finally {
            file_put_contents($fixture, $original);
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            if ($paths !== [] && is_dir(dirname($paths[0]))) {
                rmdir(dirname($paths[0]));
            }
        }
    }

    public function testSiteAndLanguageBaselinesCompareAndUpdateIndependently(): void
    {
        $finder = $this->get(SiteFinder::class);
        $preview = $finder->getSiteByIdentifier('preview');
        // The same hreflang in another site must also receive its own baseline.
        $sites = $finder->getAllSites();
        $sites['other'] = new Site('other', 98765434, $preview->getConfiguration(), $preview->getSettings(), $preview->getTypoScript());
        $this->get(CacheManager::class)->getCache('runtime')->set('sites-configuration', $sites);
        $runner = $this->get(Runner::class);
        $identifier = 'site:snapshotContext:Default';
        $paths = [];
        $baselines = [];
        $contexts = [['preview', 'de'], ['preview', 'en'], ['other', 'en']];
        try {
            foreach ($contexts as [$site, $language]) {
                $result = $runner->run($identifier, $site, $language, true);
                $paths[] = $result['path'];
                $baselines[] = $result['expected'];
                self::assertSame('created', $result['status'], $result['message']);
                self::assertStringContainsString($language === 'de' ? 'german-site-context' : 'wrong-context', $result['expected']);
                self::assertSame($result['expected'], file_get_contents($result['path']));
            }

            self::assertCount(3, array_unique($paths));
            self::assertNotSame($baselines[0], $baselines[1]);
            foreach ($contexts as [$site, $language]) {
                self::assertSame('passed', $runner->run($identifier, $site, $language)['status']);
            }

            file_put_contents($paths[1], "changed\n");
            $tester = new CommandTester(new SnapshotCommand($runner, new PreviewContextResolver($finder)));
            self::assertSame(2, $tester->execute(['site' => 'preview', 'language' => 'en', '--scope' => $identifier, '-u' => true]));
            foreach ($paths as $index => $path) {
                self::assertSame($baselines[$index], file_get_contents($path));
            }

            self::assertSame(0, $tester->execute(['site' => 'preview', 'language' => 'en', '--scope' => $identifier]));
        } finally {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            if ($paths !== [] && is_dir(dirname($paths[0]))) {
                rmdir(dirname($paths[0]));
            }
        }
    }

    public function testCliAndAuthenticatedBackendRouteTokensUseTheSameSnapshotAndDiff(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['frontend_studio']['showOwnComponents'] = '1';
        $runner = $this->get(Runner::class);
        $renderer = $this->get(FrontendRenderer::class);
        $identifier = 'frontend.studio:variant.sidebar:Default';
        $result = $runner->run($identifier, 'preview', 'en', true);
        try {
            self::assertSame('created', $result['status'], $result['message']);
            self::assertSame(4, substr_count($result['expected'], 'token=' . Comparison::MARKER));
            self::assertStringNotContainsString('dummyToken', $result['expected']);
            $cliHtml = $renderer->render($identifier, 'preview', 'en');
            self::assertMatchesRegularExpression('/token=[a-f0-9]{64}/', $cliHtml);
            self::assertStringNotContainsString('dummyToken', $cliHtml);
            file_put_contents($result['path'], str_replace('Variant tools', 'Changed tools', $result['expected']));
            $cliDiff = $runner->run($identifier, 'preview', 'en');
            self::assertSame('failed', $cliDiff['status'], $cliDiff['message']);
            self::assertStringNotContainsString('dummyToken', $cliDiff['actual']);
            self::assertSame(4, substr_count($cliDiff['actual'], 'token=' . Comparison::MARKER));

            $this->importCSVDataSet(__DIR__ . '/../Fixtures/BackendUser.csv');
            $this->setUpBackendUser(1);
            // Force TYPO3 to generate authenticated routes instead of reusing CLI URLs.
            new ReflectionProperty(UriBuilder::class, 'generated')
                ->setValue($this->get(UriBuilder::class), []);
            $this->get(CacheManager::class)->getCache('runtime')->remove('formprotection-instance-' . hash('xxh3', 'backend'));
            $backendHtml = $renderer->render($identifier, 'preview', 'en');
            self::assertMatchesRegularExpression('/token=[a-f0-9]{64}/', $backendHtml);
            self::assertStringNotContainsString('dummyToken', $backendHtml);
            $backendDiff = $runner->run($identifier, 'preview', 'en');
            self::assertSame('failed', $backendDiff['status'], $backendDiff['message']);
            self::assertSame($cliDiff['actual'], $backendDiff['actual']);
            self::assertSame($cliDiff['expected'], $backendDiff['expected']);
            file_put_contents($result['path'], $result['expected']);
            self::assertSame('passed', $runner->run($identifier, 'preview', 'en')['status']);
        } finally {
            if (is_file($result['path'])) {
                unlink($result['path']);
                rmdir(dirname($result['path']));
            }
        }
    }

    public function testRendersTextWithTranslationsStringableWrapperAndSpecialVariantNames(): void
    {
        $runner = $this->get(Runner::class);
        $identifiers = $runner->discover('site:text');
        self::assertCount(2, $identifiers);
        foreach ($identifiers as $identifier) {
            $result = $runner->run($identifier, 'preview', 'en', true);
            try {
                self::assertSame('created', $result['status'], $result['message']);
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
        $tester = new CommandTester(new SnapshotCommand($runner, new PreviewContextResolver($this->get(SiteFinder::class))));
        $arguments = ['site' => 'relative', 'language' => 'en-us', '--scope' => 'site:text'];
        $paths = [];
        foreach ($runner->discover('site:text') as $identifier) {
            $paths[] = __DIR__ . '/../Fixtures/Extensions/preview_site_set/Resources/Private/Components/Text/Text.fluid.html-snapshots/html-'
                . rawurlencode(explode(':', $identifier, 3)[2]) . '@relative@en-us.snapshot.html';
        }

        try {
            self::assertSame(2, $tester->execute($arguments));
            self::assertStringContainsString('WARNING (MISSING)', $tester->getDisplay());
            self::assertStringNotContainsString('Stack trace:', $tester->getDisplay());
            foreach ($paths as $path) {
                self::assertFileDoesNotExist($path);
            }

            self::assertSame(2, $tester->execute([...$arguments, '-u' => true]));
            self::assertSame(2, substr_count($tester->getDisplay(), 'CREATED '));
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

    /** @param array<string, string> $arguments */
    #[DataProvider('defaultCommandContexts')]
    public function testCommandUsesBackendDefaultsForOmittedArguments(array $arguments, string $site, string $language): void
    {
        $runner = $this->get(Runner::class);
        $resolver = new PreviewContextResolver($this->get(SiteFinder::class));
        self::assertSame([$site, $language], $resolver->resolve(new ServerRequest('/'), $arguments['site'] ?? null, $arguments['language'] ?? null));
        $tester = new CommandTester(new SnapshotCommand($runner, $resolver));
        $path = __DIR__ . '/../Fixtures/Extensions/preview_site_set/Resources/Private/Components/SnapshotContext/SnapshotContext.fluid.html-snapshots/html-Default@' . rawurlencode($site) . '@' . rawurlencode($language) . '.snapshot.html';
        try {
            self::assertSame(2, $tester->execute([...$arguments, '--scope' => 'site:snapshotContext:Default']));
            self::assertFileDoesNotExist($path);
            self::assertSame(2, $tester->execute([...$arguments, '--scope' => 'site:snapshotContext:Default', '-u' => true]));
            self::assertStringContainsString('Created snapshot.', $tester->getDisplay());
            self::assertSame('passed', $runner->run('site:snapshotContext:Default', $site, $language)['status']);
            self::assertSame(0, $tester->execute([...$arguments, '--scope' => 'site:snapshotContext:Default']));
        } finally {
            if (is_file($path)) {
                unlink($path);
                rmdir(dirname($path));
            }
        }
    }

    /** @return iterable<string, array{array<string, string>, string, string}> */
    public static function defaultCommandContexts(): iterable
    {
        yield 'both omitted' => [[], 'preview', 'de'];
        yield 'language omitted' => [['site' => 'preview'], 'preview', 'de'];
        yield 'language belongs to selected site' => [['site' => 'relative'], 'relative', 'en-us'];
        yield 'site omitted with explicit language' => [['language' => 'en'], 'preview', 'en'];
    }

    /** @param array<string, Site> $sites */
    #[DataProvider('unavailableCommandDefaults')]
    public function testCommandReportsUnavailableDefaults(array $sites, string $message): void
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn($sites);
        $tester = new CommandTester(new SnapshotCommand($this->get(Runner::class), new PreviewContextResolver($siteFinder)));

        self::assertSame(1, $tester->execute(['--scope' => 'site:wrappedCard']));
        self::assertStringContainsString($message, $tester->getDisplay());
    }

    /** @return iterable<string, array{array<string, Site>, string}> */
    public static function unavailableCommandDefaults(): iterable
    {
        yield 'no configured sites' => [[], 'No TYPO3 sites are configured'];
        yield 'no enabled languages' => [['empty' => new Site('empty', 1, [
            'base' => '/',
            'languages' => [[
                'languageId' => 0,
                'title' => 'Disabled',
                'locale' => 'en-US',
                'hreflang' => 'en',
                'base' => '/',
                'enabled' => false,
            ]],
        ])], 'Snapshot site "empty" has no enabled languages.'];
    }

    public function testCommandReportsExceptionsWithTracesAndMismatchWarningsWithoutTraces(): void
    {
        $runner = $this->get(Runner::class);
        $tester = new CommandTester(new SnapshotCommand($runner, new PreviewContextResolver($this->get(SiteFinder::class))));
        $arguments = ['site' => 'preview', 'language' => 'unknown', '--scope' => 'site:wrappedCard'];
        self::assertSame(1, $tester->execute($arguments));
        self::assertStringContainsString('RuntimeException:', $tester->getDisplay());
        self::assertStringNotContainsString('Stack trace:', $tester->getDisplay());
        self::assertStringNotContainsString('.snapshot.html', $tester->getDisplay());
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
        $result = $runner->run('site:wrappedCard:Default', 'preview', 'en', true);
        try {
            self::assertSame('created', $result['status'], $result['message']);
            file_put_contents($result['path'], "changed\n");
            self::assertSame(1, $tester->execute($arguments));
            self::assertStringContainsString('WARNING (MISMATCH)', $tester->getDisplay());
            self::assertStringContainsString('snapshot | actual', $tester->getDisplay());
            self::assertStringContainsString('To accept these changes, rerun this command with --update outside Production.', $tester->getDisplay());
            self::assertStringContainsString('Review and commit the updated snapshot files, then rerun without --update to verify.', $tester->getDisplay());
            self::assertStringContainsString('html-Default@preview@en.snapshot.html', $tester->getDisplay());
            self::assertStringNotContainsString('EXPECTED:', $tester->getDisplay());
            self::assertStringNotContainsString('ACTUAL:', $tester->getDisplay());
            self::assertStringNotContainsString('Stack trace:', $tester->getDisplay());
            self::assertStringNotContainsString('RuntimeException:', $tester->getDisplay());
            self::assertSame(1, $tester->execute($arguments, ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]));
            self::assertStringContainsString('To accept these changes, rerun this command with --update outside Production.', $tester->getDisplay());
            self::assertStringContainsString('html-Default@preview@en.snapshot.html', $tester->getDisplay());
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

    public function testCommandColorsLabelsAndShowsRelativePathsForWarningsOrVerboseOutput(): void
    {
        $runner = $this->get(Runner::class);
        $tester = new CommandTester(new SnapshotCommand($runner, new PreviewContextResolver($this->get(SiteFinder::class))));
        $arguments = ['site' => 'preview', 'language' => 'en', '--scope' => 'site:wrappedCard'];
        $path = __DIR__ . '/../Fixtures/Extensions/preview_site_set/Resources/Private/Components/WrappedCard/WrappedCard.fluid.html-snapshots/html-Default@preview@en.snapshot.html';
        try {
            self::assertSame(2, $tester->execute($arguments, ['decorated' => true]));
            $output = $tester->getDisplay();
            self::assertStringContainsString("\033[33;1mWARNING (MISSING)", $output);
            self::assertStringContainsString("\033[36msite:wrappedCard", $output);
            self::assertStringContainsString("\033[35mDefault", $output);
            self::assertFileDoesNotExist($path);
            self::assertSame(2, $tester->execute([...$arguments, '-u' => true], ['decorated' => true]));
            self::assertStringContainsString("\033[33;1mCREATED", $tester->getDisplay());
            self::assertStringContainsString("\033[33;1m1/1 snapshots ready (1 created, 0 updated).", $tester->getDisplay());
            self::assertStringNotContainsString('.snapshot.html', $tester->getDisplay());
            $absolutePath = realpath($path);
            self::assertNotFalse($absolutePath);
            self::assertStringContainsString(Path::makeRelative($absolutePath, Environment::getProjectPath()), $output);
            self::assertStringNotContainsString(Environment::getProjectPath() . '/', $output);
            self::assertStringNotContainsString('dynamic markers', strtolower($output));
            self::assertStringNotContainsString('To accept this change', $output);

            self::assertSame(0, $tester->execute($arguments, ['decorated' => true]));
            self::assertStringContainsString("\033[32;1mPASSED", $tester->getDisplay());
            self::assertStringNotContainsString('To accept this change', $tester->getDisplay());
            self::assertStringNotContainsString('.snapshot.html', $tester->getDisplay());
            self::assertSame(0, $tester->execute($arguments, ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]));
            $absolutePath = realpath($path);
            self::assertNotFalse($absolutePath);
            self::assertStringContainsString(Path::makeRelative($absolutePath, Environment::getProjectPath()), $tester->getDisplay());
            self::assertStringNotContainsString(Environment::getProjectPath() . '/', $tester->getDisplay());

            $baseline = file_get_contents($path);
            self::assertNotFalse($baseline);
            $formatter = new HtmlFormatter();
            $spacedBaseline = $formatter->format(str_replace(' ', " \t ", $formatter->original($baseline)));
            file_put_contents($path, $spacedBaseline);
            self::assertSame(0, $tester->execute($arguments));
            self::assertSame($spacedBaseline, file_get_contents($path));
            self::assertStringNotContainsString('Dynamic markers used.', $tester->getDisplay());
            $lines = explode("\n", str_replace('Wrapped', Comparison::MARKER, $baseline));
            file_put_contents($path, implode("\n", $lines));
            self::assertSame(0, $tester->execute($arguments));
            self::assertSame("PASSED site:wrappedCard:Default\n1/1 passed.\n", $tester->getDisplay());
            file_put_contents($path, str_replace('preview', 'broken', implode("\n", $lines)));
            self::assertSame(1, $tester->execute($arguments));
            self::assertStringContainsString('WARNING (MISMATCH)', $tester->getDisplay());
            self::assertStringContainsString('snapshot | actual', $tester->getDisplay());

            $lines[0] = '<!-- frontend-studio:dynamic-line -->';
            file_put_contents($path, implode("\n", $lines));
            self::assertSame(1, $tester->execute($arguments));
            self::assertStringContainsString('Regenerate this snapshot with --update', $tester->getDisplay());
            self::assertSame(2, $tester->execute([...$arguments, '--update' => true]));
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
        $tester = new CommandTester(new SnapshotCommand($runner, new PreviewContextResolver($this->get(SiteFinder::class))));
        $arguments = ['site' => 'preview', 'language' => 'en', '--scope' => 'site:text'];
        $paths = [];
        try {
            foreach ([...$runner->discover('site:text'), 'site:wrappedCard:Default'] as $identifier) {
                $result = $runner->run($identifier, 'preview', 'en', true);
                $paths[] = $result['path'];
                self::assertSame('created', $result['status'], $result['message']);
                file_put_contents($result['path'], "changed\n");
            }

            self::assertSame(1, $tester->execute($arguments));
            $output = $tester->getDisplay();
            self::assertSame(2, substr_count($output, 'WARNING (MISMATCH)'));
            foreach (array_slice($paths, 0, 2) as $path) {
                self::assertStringContainsString(Path::makeRelative($path, Environment::getProjectPath()), $output);
            }

            self::assertSame(1, substr_count($output, 'To accept these changes'));
            self::assertGreaterThan(strrpos($output, '[-removed-]'), strpos($output, 'To accept these changes'));
            foreach ($paths as $path) {
                self::assertSame("changed\n", file_get_contents($path));
            }

            self::assertSame(2, $tester->execute([...$arguments, '-u' => true], ['decorated' => true]));
            self::assertSame(2, substr_count($tester->getDisplay(), "\033[33;1mUPDATED"));
            self::assertStringContainsString('2/2 snapshots ready (2 updated).', $tester->getDisplay());
            self::assertStringNotContainsString('To accept these changes', $tester->getDisplay());
            self::assertStringNotContainsString('html-Simple%20Test@preview@en.snapshot.html', $tester->getDisplay());
            self::assertSame("changed\n", file_get_contents($paths[2]));
            self::assertSame(0, $tester->execute($arguments));
            self::assertStringContainsString('2/2 passed.', $tester->getDisplay());

            self::assertSame(0, $tester->execute([...$arguments, '--update' => true]));
            self::assertStringContainsString('2/2 snapshots ready (0 updated).', $tester->getDisplay());
            $baseline = file_get_contents($paths[0]);
            self::assertSame(1, $tester->execute([...$arguments, 'language' => 'unknown', '--update' => true]));
            self::assertSame($baseline, file_get_contents($paths[0]));

            unlink($paths[0]);
            file_put_contents($paths[1], "changed\n");
            self::assertSame(3, $tester->execute($arguments));
            $output = $tester->getDisplay();
            self::assertSame(1, substr_count($output, 'WARNING (MISSING)'));
            self::assertSame(1, substr_count($output, 'WARNING (MISMATCH)'));
            self::assertSame(1, substr_count($output, 'To create missing snapshots and accept these changes'));
            self::assertGreaterThan(strrpos($output, 'WARNING ('), strpos($output, 'To create missing snapshots'));
            self::assertFileDoesNotExist($paths[0]);
            self::assertSame("changed\n", file_get_contents($paths[1]));
            self::assertSame(2, $tester->execute([...$arguments, '--update' => true]));
            self::assertStringContainsString('CREATED ', $tester->getDisplay());
            self::assertStringContainsString('UPDATED ', $tester->getDisplay());
            self::assertStringContainsString('2/2 snapshots ready (1 created, 1 updated).', $tester->getDisplay());
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

    private function replaceTemplate(string $path, string $content): void
    {
        clearstatcache(true, $path);
        $modified = filemtime($path);
        self::assertNotFalse($modified);
        file_put_contents($path, $content);
        // Fluid identifies compiled templates by path and second-precision mtime.
        touch($path, $modified + 1);
        clearstatcache(true, $path);
        $this->get(CacheManager::class)->getCache('fluid_component_definitions')->flush();
    }
}
