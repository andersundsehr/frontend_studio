<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service\Snapshot;

use Andersundsehr\FrontendStudio\Service\Snapshot\Comparison;
use Andersundsehr\FrontendStudio\Service\Snapshot\HtmlFormatter;
use Andersundsehr\FrontendStudio\Service\Snapshot\InlineDiff;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class InlineDiffTest extends TestCase
{
    #[DataProvider('inlineWhitespaceChanges')]
    public function testFormattedDiffShowsWhitespaceChangesAtSavedFileLineNumbers(string $withoutSpace, string $withSpace): void
    {
        $formatter = new HtmlFormatter();
        $diff = new InlineDiff();
        foreach ([[$withoutSpace, $withSpace], [$withSpace, $withoutSpace]] as [$before, $after]) {
            $expected = $formatter->format($before);
            $actual = $formatter->format($after);
            $plain = new OutputFormatter()->format($diff->render($expected, $actual));
            self::assertNotNull($plain);
            self::assertMatchesRegularExpression('/^- \d+ \| - /m', $plain);
            self::assertMatchesRegularExpression('/^\+ - \| \d+ /m', $plain);
            self::assertStringNotContainsString('snapshot-format', $plain);
            preg_match_all('/^[+-] (\d+|-) \| (\d+|-) /m', $plain, $lines, PREG_SET_ORDER);
            foreach ($lines as $line) {
                foreach ([1 => $expected, 2 => $actual] as $side => $source) {
                    if ($line[$side] !== '-') {
                        self::assertGreaterThan(1, (int)$line[$side], 'The format header is not rendered as HTML in the diff.');
                        self::assertLessThanOrEqual(substr_count($source, "\n"), (int)$line[$side]);
                    }
                }
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function inlineWhitespaceChanges(): iterable
    {
        yield 'leading paragraph space' => ['<p>Hello</p>', '<p> Hello</p>'];
        yield 'trailing paragraph space' => ['<p>Hello</p>', '<p>Hello </p>'];
        yield 'leading container space' => ['<div>Hello</div>', '<div> Hello</div>'];
        yield 'trailing container space' => ['<div>Hello</div>', '<div>Hello </div>'];
        yield 'text before inline tag' => ['<p>Hello<strong>world</strong></p>', '<p>Hello <strong>world</strong></p>'];
        yield 'adjacent inline elements' => ['<strong>Hello</strong><em>world</em>', '<strong>Hello</strong> <em>world</em>'];
    }

    #[DataProvider('textLineNumbers')]
    public function testFormattedTextKeepsPhysicalDiffLineNumbers(string $before, string $after, int $expectedLine, int $actualLine): void
    {
        $formatter = new HtmlFormatter();
        $expected = $formatter->format($before);
        $actual = $formatter->format($after);
        self::assertStringContainsString('old', explode("\n", $expected)[$expectedLine - 1]);
        self::assertStringContainsString('new', explode("\n", $actual)[$actualLine - 1]);
        $plain = new OutputFormatter()->format(new InlineDiff()->render($expected, $actual));
        self::assertNotNull($plain);
        self::assertMatchesRegularExpression('/^- ' . $expectedLine . ' \\| - .*old/m', $plain);
        self::assertMatchesRegularExpression('/^\\+ - \\| ' . $actualLine . ' .*new/m', $plain);
        self::assertStringNotContainsString('snapshot-whitespace', $plain);
        self::assertStringNotContainsString('snapshot-format', $plain);
    }

    /** @return iterable<string, array{string, string, int, int}> */
    public static function textLineNumbers(): iterable
    {
        yield 'container text' => ['<div>old</div>', '<div>new</div>', 3, 3];
        yield 'paragraph with no spaces' => ['<p>old</p>', '<p>new</p>', 2, 2];
        yield 'paragraph with a leading space' => ['<p> old</p>', '<p> new</p>', 3, 3];
        yield 'paragraph with a trailing space' => ['<p>old </p>', '<p>new </p>', 2, 2];
        yield 'added leading whitespace changes the text line' => ['<p>old</p>', '<p> new</p>', 2, 3];
        yield 'removed leading whitespace changes the text line' => ['<p> old</p>', '<p>new</p>', 3, 2];
        yield 'reused CRLF and tabs' => ["<div>\r\n\told\r\n</div>", "<div>\n  new\n</div>", 3, 3];
        $value = str_repeat('x', 90);
        yield 'container with multiline attributes' => ['<div title="' . $value . '">old</div>', '<div title="' . $value . '">new</div>', 5, 5];
    }

    public function testFormattedDiffIgnoresWhitespaceRunLengthAndLayout(): void
    {
        $formatter = new HtmlFormatter();
        $expected = $formatter->format('<p>Hello <strong>world</strong></p>');
        $actual = $formatter->format("<p>Hello\n\t<strong>world</strong></p>");
        self::assertSame('<fg=gray>  snapshot | actual</>', new InlineDiff()->render($expected, $actual));
    }

    public function testReusedLayoutAndMetadataKeepAccuratePhysicalFileLineNumbers(): void
    {
        $formatter = new HtmlFormatter();
        $expected = $formatter->format("<div>\r\n\t<p>Hello old world</p>\r\n\r\n</div>\r\n");
        $actual = $formatter->format("<div>\n  <p>Hello new world</p>\n\n</div>\n");
        $plain = new OutputFormatter()->format(new InlineDiff()->render($expected, $actual));
        self::assertNotNull($plain);
        self::assertStringContainsString('- 3 | -    <p>Hello old ', $plain);
        self::assertStringContainsString('+ - | 3    <p>Hello new ', $plain);
        self::assertStringNotContainsString('snapshot-whitespace', $plain);
        self::assertStringNotContainsString('snapshot-format', $plain);
        self::assertTrue(new Comparison()->matches($expected, $formatter->format("<div>\n <p>Hello old world</p>\n\n</div>\n")));
    }

    public function testInlineDiffShowsColoredEditsAndEscapesConsoleMarkup(): void
    {
        $diff = new InlineDiff()->render('<p>Café: old word</p><error>text</error>', '<p>Café: new word</p><error>text</error>');
        self::assertStringContainsString('<fg=red>old </>', $diff);
        self::assertStringContainsString('<fg=green>new </>', $diff);
        $plain = new OutputFormatter()->format($diff);
        self::assertNotNull($plain);
        self::assertStringContainsString('- 1 | -  <p>Café: old word</p><error>text</error>', $plain);
        self::assertStringContainsString('+ - | 1  <p>Café: new word</p><error>text</error>', $plain);
        self::assertStringContainsString('Café: <fg=red>old </>word', $diff);
        self::assertStringContainsString('Café: <fg=green>new </>word', $diff);
        self::assertStringContainsString('<error>text', $plain);
        self::assertStringNotContainsString("\033[", $plain);
        $colored = new OutputFormatter(true)->format($diff);
        self::assertNotNull($colored);
        self::assertStringContainsString("\033[", $colored);
    }

    public function testWhitespacePresenceChangesUseNormalSpaces(): void
    {
        $diff = new InlineDiff();
        $plain = new OutputFormatter()->format($diff->render('<p>one two</p>', '<p>onetwo</p>'));
        self::assertNotNull($plain);
        self::assertStringContainsString('- 1 | -  <p>one two</p>', $plain);
        self::assertStringContainsString('+ - | 1  <p>onetwo</p>', $plain);
        self::assertStringNotContainsString('␠', $plain);
        $attribute = $diff->render('<div class="card">', '<div data-component-identifier="" class="card">');
        self::assertStringContainsString('data-component-identifier="" ', $attribute);
        self::assertStringNotContainsString('␠', $attribute);
        self::assertSame($diff->render('<p>one two</p>', '<p>one two</p>'), $diff->render("<p>one \t\n two</p>", '<p>one two</p>'));
    }

    public function testHighlightsCompleteWordsWithSharedPrefixesAndSuffixes(): void
    {
        $diff = new InlineDiff()->render('<p>Token abcXdef ready</p>', '<p>Token abcYdef ready</p>');
        $plain = new OutputFormatter()->format($diff);
        self::assertStringContainsString('<fg=red>abcXdef </>', $diff);
        self::assertStringContainsString('<fg=green>abcYdef </>', $diff);
        self::assertNotNull($plain);
        self::assertStringContainsString('- 1 | -  <p>Token abcXdef ready</p>', $plain);
        self::assertStringContainsString('+ - | 1  <p>Token abcYdef ready</p>', $plain);
        self::assertStringNotContainsString('<fg=red>X</>', $diff);
    }

    public function testInlineDynamicValuesAreExcludedFromStableDiffs(): void
    {
        $diff = new InlineDiff()->render('<p id="' . Comparison::MARKER . '">Status old value</p>', '<p id="random">Status new value</p>');
        self::assertStringContainsString('old ', $diff);
        self::assertStringContainsString('new ', $diff);
        self::assertStringNotContainsString('random', $diff);
    }

    #[DataProvider('wordChanges')]
    public function testWordChangesHaveSeparateLinesWithOnlyChangedWordsColored(string $expected, string $actual, string $removed, string $added): void
    {
        $diff = new InlineDiff()->render($expected, $actual);
        $plain = new OutputFormatter()->format($diff);
        self::assertNotNull($plain);
        self::assertStringContainsString('- 1 | -  ' . $removed, $plain);
        self::assertStringContainsString('+ - | 1  ' . $added, $plain);
        self::assertStringNotContainsString('<fg=red>\\<p', $diff);
        self::assertStringNotContainsString('<fg=green>\\<p', $diff);
        self::assertStringNotContainsString('(-removed-)', $plain);
        self::assertStringNotContainsString('(+added+)', $plain);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function wordChanges(): iterable
    {
        yield 'word replacement' => ['<p>Hello old world</p>', '<p>Hello new world</p>', '<p>Hello old world</p>', '<p>Hello new world</p>'];
        yield 'word insertion' => ['<p>Hello world</p>', '<p>Hello new world</p>', '<p>Hello world</p>', '<p>Hello new world</p>'];
        yield 'word deletion' => ['<p>Hello old world</p>', '<p>Hello world</p>', '<p>Hello old world</p>', '<p>Hello world</p>'];
        yield 'multiple changed words' => ['<p>Hello old world old end</p>', '<p>Hello new world new end</p>', '<p>Hello old world old end</p>', '<p>Hello new world new end</p>'];
        yield 'literal delimiters stay unchanged' => ['<p>Hello (-keep-) old end</p>', '<p>Hello (-keep-) new end</p>', '<p>Hello (-keep-) old end</p>', '<p>Hello (-keep-) new end</p>'];
    }

    public function testLongUnchangedSectionsAreCollapsed(): void
    {
        $start = str_repeat("<p>unchanged</p>\n", 20);
        $diff = new InlineDiff()->render($start . '<p>old</p>', $start . '<p>new</p>');
        self::assertStringContainsString('…', $diff);
        self::assertLessThan(6, substr_count($diff, 'unchanged'));
        self::assertStringContainsString('<fg=red>-</>', $diff);
    }

    #[DataProvider('alignedLineContexts')]
    public function testContextAndChangedLinesKeepCodeAligned(string $expected, string $actual): void
    {
        $plain = new OutputFormatter()->format(new InlineDiff()->render($expected, $actual));
        self::assertNotNull($plain);
        $offsets = [];
        foreach (explode("\n", $plain) as $line) {
            $offset = strpos($line, '<p>');
            if ($offset !== false) {
                $offsets[] = $offset;
            }
        }

        self::assertGreaterThanOrEqual(4, count($offsets));
        self::assertCount(1, array_unique($offsets));
        self::assertMatchesRegularExpression('/^  [ 0-9]+ \| [ 0-9]+  <p>/m', $plain);
        self::assertMatchesRegularExpression('/^- [ 0-9]+ \| [ -]+  <p>/m', $plain);
        self::assertMatchesRegularExpression('/^\+ [ -]+ \| [ 0-9]+  <p>/m', $plain);
    }

    /** @return iterable<string, array{string, string}> */
    public static function alignedLineContexts(): iterable
    {
        yield 'single digit line numbers' => ["<p>same</p>\n<p>Hello old end</p>\n<p>after</p>", "<p>same</p>\n<p>Hello new end</p>\n<p>after</p>"];
        $context = str_repeat("<p>same</p>\n", 10);
        yield 'double digit line numbers' => [$context . "<p>Hello old end</p>\n<p>after</p>", $context . "<p>Hello new end</p>\n<p>after</p>"];
    }

    public function testLineNumbersReferToEachOriginalSource(): void
    {
        $diff = new OutputFormatter()->format(new InlineDiff()->render("<p>same</p>\n\n<p>old</p>\n", "<p>same</p>\n<p>new</p>\n"));
        self::assertNotNull($diff);
        self::assertStringContainsString('snapshot | actual', $diff);
        self::assertStringContainsString('1 | 1  <p>same</p>', $diff);
        self::assertStringContainsString('- 3 | -  <p>old</p>', $diff);
        self::assertStringContainsString('+ - | 2  <p>new</p>', $diff);
    }

    public function testInsertedAndDeletedLinesHaveSeparateSourceNumbers(): void
    {
        $diff = new OutputFormatter()->format(new InlineDiff()->render("<p>one</p>\n<p>two</p>\n", "<p>one</p>\n<p>added</p>\n<p>two</p>\n"));
        self::assertNotNull($diff);
        self::assertStringContainsString('+ - | 2  <p>added</p>', $diff);
        self::assertStringContainsString('2 | 3', $diff);
        $deleted = new OutputFormatter()->format(new InlineDiff()->render("<p>one</p>\n<p>added</p>\n<p>two</p>\n", "<p>one</p>\n<p>two</p>\n"));
        self::assertNotNull($deleted);
        self::assertStringContainsString('- 2 | -  <p>added</p>', $deleted);
        self::assertStringContainsString('3 | 2', $deleted);
    }

    #[DataProvider('indentedSources')]
    public function testDiffPreservesSourceIndentationAndHighlightsOnlyChangedWords(string $expected, string $actual, string $removedLine, string $addedLine): void
    {
        $diff = new InlineDiff()->render($expected, $actual);
        $plain = new OutputFormatter()->format($diff);
        self::assertNotNull($plain);
        self::assertStringContainsString($removedLine, $plain);
        self::assertStringContainsString($addedLine, $plain);
        self::assertStringContainsString('<fg=red>old ', $diff);
        self::assertStringContainsString('<fg=green>new ', $diff);
        self::assertStringNotContainsString('<fg=red>  ', $diff);
        self::assertStringNotContainsString('<fg=green>  ', $diff);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function indentedSources(): iterable
    {
        yield 'nested HTML content' => [
            "<div>\n  <section>\n    <p>Hello old world</p>\n  </section>\n</div>\n",
            "<div>\n  <section>\n    <p>Hello new world</p>\n  </section>\n</div>\n",
            '- 3 | -      <p>Hello old world</p>',
            '+ - | 3      <p>Hello new world</p>',
        ];
        yield 'split attributes' => [
            "<div>\n  <p\n    title=\"Hello old world\"\n    class=\"card\"\n  >Content</p>\n</div>\n",
            "<div>\n  <p\n    title=\"Hello new world\"\n    class=\"card\"\n  >Content</p>\n</div>\n",
            '- 3 | -      title="Hello old world"',
            '+ - | 3      title="Hello new world"',
        ];
        yield 'each source keeps its own indentation' => [
            "<div>\n  <p>Hello old world</p>\n</div>\n",
            "<div>\n      <p>Hello new world</p>\n</div>\n",
            '- 2 | -    <p>Hello old world</p>',
            '+ - | 2        <p>Hello new world</p>',
        ];
        yield 'first line has no duplicate normalized indent' => [
            '    <p>Hello old world</p>',
            '    <p>Hello new world</p>',
            '- 1 | -      <p>Hello old world</p>',
            '+ - | 1      <p>Hello new world</p>',
        ];
        yield 'tabs are preserved as indentation' => [
            "<div>\n\t<p>Hello old world</p>\n</div>\n",
            "<div>\n\t<p>Hello new world</p>\n</div>\n",
            "- 2 | -  \t<p>Hello old world</p>",
            "+ - | 2  \t<p>Hello new world</p>",
        ];
    }

    public function testUnchangedContextKeepsItsNestingIndentation(): void
    {
        $expected = "<div>\n  <section>\n    <p>Hello old world</p>\n  </section>\n</div>\n";
        $actual = str_replace('old', 'new', $expected);
        $plain = new OutputFormatter()->format(new InlineDiff()->render($expected, $actual));
        self::assertNotNull($plain);
        self::assertStringContainsString('  2 | 2    <section>', $plain);
        self::assertStringContainsString('  4 | 4    </section>', $plain);
        self::assertStringContainsString('  5 | 5  </div>', $plain);
    }

    public function testAddedAndRemovedLinesKeepTheirSourceIndentation(): void
    {
        $before = "<div>\n  <p>One</p>\n</div>\n";
        $after = "<div>\n  <p>One</p>\n  <section>\n    <p>Added</p>\n  </section>\n</div>\n";
        $added = new OutputFormatter()->format(new InlineDiff()->render($before, $after));
        self::assertNotNull($added);
        self::assertStringContainsString('+ - | 3    <section>', $added);
        self::assertStringContainsString('+ - | 4      <p>Added</p>', $added);
        self::assertStringContainsString('+ - | 5    </section>', $added);
        $removed = new OutputFormatter()->format(new InlineDiff()->render($after, $before));
        self::assertNotNull($removed);
        self::assertStringContainsString('- 3 | -    <section>', $removed);
        self::assertStringContainsString('- 4 | -      <p>Added</p>', $removed);
        self::assertStringContainsString('- 5 | -    </section>', $removed);
    }

    public function testIndentationLengthDoesNotCreateChanges(): void
    {
        $expected = "<div>\n  <p>Content</p>\n</div>\n";
        $actual = "<div>\n\t\t    <p>Content</p>\n</div>\n";
        self::assertTrue(new Comparison()->matches($expected, $actual));
        self::assertSame('<fg=gray>  snapshot | actual</>', new InlineDiff()->render($expected, $actual));
    }

    #[DataProvider('completeLines')]
    public function testCompleteLineChangesUseOnlyLeadingSigns(string $expected, string $actual, string $removed, string $added): void
    {
        $diff = new InlineDiff()->render($expected, $actual);
        $plain = new OutputFormatter()->format($diff);
        self::assertNotNull($plain);
        $body = substr($plain, strpos($plain, "\n") + 1);
        self::assertStringNotContainsString('(-', $body);
        self::assertStringNotContainsString('(+', $body);
        self::assertStringNotContainsString('␠', $body);
        if ($removed !== '') {
            self::assertStringContainsString('- 1 | -  ' . $removed, $body);
            self::assertStringContainsString('<fg=red>-</>', $diff);
        }

        if ($added !== '') {
            self::assertStringContainsString('+ - | 1  ' . $added, $body);
            self::assertStringContainsString('<fg=green>+</>', $diff);
        }
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function completeLines(): iterable
    {
        yield 'added HTML line with Unicode' => ['', '<span aria-hidden="true">⚠</span>', '', '<span aria-hidden="true">⚠</span>'];
        yield 'removed HTML line with Unicode' => ['<span aria-hidden="true">⚠</span>', '', '<span aria-hidden="true">⚠</span>', ''];
        yield 'replaced HTML line' => ['<p>old</p>', '<p>new</p>', '<p>old</p>', '<p>new</p>'];
        yield 'console markup remains literal' => ['', '<error>failure</error>', '', '<error>failure</error>'];
    }
}
