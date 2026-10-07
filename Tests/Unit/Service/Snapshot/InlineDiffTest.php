<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service\Snapshot;

use Andersundsehr\FrontendStudio\Service\Snapshot\Comparison;
use Andersundsehr\FrontendStudio\Service\Snapshot\InlineDiff;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class InlineDiffTest extends TestCase
{
    public function testInlineDiffShowsColoredEditsAndEscapesConsoleMarkup(): void
    {
        $diff = new InlineDiff()->render('<p>Café: old word</p><error>text</error>', '<p>Café: new word</p><error>text</error>');
        self::assertStringContainsString('<fg=red>(-old -)</>', $diff);
        self::assertStringContainsString('<fg=green>(+new +)</>', $diff);
        $plain = new OutputFormatter()->format($diff);
        self::assertNotNull($plain);
        self::assertStringContainsString('Café: (-old -)(+new +)', $plain);
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
        self::assertStringContainsString('(+data-component-identifier="" +)', $attribute);
        self::assertStringNotContainsString('␠', $attribute);
        self::assertSame($diff->render('<p>one two</p>', '<p>one two</p>'), $diff->render("<p>one \t\n two</p>", '<p>one two</p>'));
    }

    public function testHighlightsCompleteWordsWithSharedPrefixesAndSuffixes(): void
    {
        $plain = new OutputFormatter()->format(new InlineDiff()->render('<p>Token abcXdef ready</p>', '<p>Token abcYdef ready</p>'));
        self::assertNotNull($plain);
        self::assertStringContainsString('(-abcXdef -)(+abcYdef +)', $plain);
        self::assertStringNotContainsString('abc(-X-)(+Y+)def', $plain);
    }

    public function testInlineDynamicValuesAreExcludedFromStableDiffs(): void
    {
        $diff = new InlineDiff()->render('<p id="' . Comparison::MARKER . '">Status old value</p>', '<p id="random">Status new value</p>');
        self::assertStringContainsString('(-old -)', $diff);
        self::assertStringContainsString('(+new +)', $diff);
        self::assertStringNotContainsString('random', $diff);
    }

    public function testLongUnchangedSectionsAreCollapsed(): void
    {
        $start = str_repeat("<p>unchanged</p>\n", 20);
        $diff = new InlineDiff()->render($start . '<p>old</p>', $start . '<p>new</p>');
        self::assertStringContainsString('…', $diff);
        self::assertLessThan(6, substr_count($diff, 'unchanged'));
        self::assertStringContainsString('<fg=red>-</>', $diff);
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
