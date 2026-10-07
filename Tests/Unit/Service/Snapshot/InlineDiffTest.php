<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service\Snapshot;

use Andersundsehr\FrontendStudio\Service\Snapshot\Comparison;
use Andersundsehr\FrontendStudio\Service\Snapshot\InlineDiff;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class InlineDiffTest extends TestCase
{
    public function testInlineDiffShowsColoredEditsAndEscapesConsoleMarkup(): void
    {
        $diff = new InlineDiff()->render('<p>Café: old</p><error>text</error>', '<p>Café: new</p><error>text</error>');
        self::assertStringContainsString('<fg=red>[-old-]</>', $diff);
        self::assertStringContainsString('<fg=green>{+new+}</>', $diff);
        $plain = new OutputFormatter()->format($diff);
        self::assertNotNull($plain);
        self::assertStringContainsString('Café: [-old-]{+new+}', $plain);
        self::assertStringContainsString('<error>text', $plain);
        self::assertStringNotContainsString("\033[", $plain);
        $colored = new OutputFormatter(true)->format($diff);
        self::assertNotNull($colored);
        self::assertStringContainsString("\033[", $colored);
    }

    public function testWhitespacePresenceChangesAreVisible(): void
    {
        $diff = new InlineDiff();
        self::assertStringContainsString('[-␠-]', $diff->render('<p>one two</p>', '<p>onetwo</p>'));
        self::assertStringContainsString('{+␠+}', $diff->render('<p>onetwo</p>', '<p>one two</p>'));
        self::assertSame($diff->render('<p>one two</p>', '<p>one two</p>'), $diff->render("<p>one \t\n two</p>", '<p>one two</p>'));
    }

    public function testInlineDynamicValuesAreExcludedFromStableDiffs(): void
    {
        $diff = new InlineDiff()->render('<p id="' . Comparison::MARKER . '">old</p>', '<p id="random">new</p>');
        self::assertStringContainsString('[-old-]', $diff);
        self::assertStringContainsString('{+new+}', $diff);
        self::assertStringNotContainsString('random', $diff);
    }

    public function testLongUnchangedSectionsAreCollapsed(): void
    {
        $start = str_repeat("<p>unchanged</p>\n", 20);
        $diff = new InlineDiff()->render($start . '<p>old</p>', $start . '<p>new</p>');
        self::assertStringContainsString('…', $diff);
        self::assertLessThan(6, substr_count($diff, 'unchanged'));
        self::assertStringContainsString('[-old-]', $diff);
    }

    public function testLineNumbersReferToEachOriginalSource(): void
    {
        $diff = new OutputFormatter()->format(new InlineDiff()->render("<p>same</p>\n\n<p>old</p>\n", "<p>same</p>\n<p>new</p>\n"));
        self::assertNotNull($diff);
        self::assertStringContainsString('snapshot | actual', $diff);
        self::assertStringContainsString('1 | 1  <p>same</p>', $diff);
        self::assertStringContainsString('3 | 2  <p>[-old-]{+new+}</p>', $diff);
    }

    public function testInsertedAndDeletedLinesHaveSeparateSourceNumbers(): void
    {
        $diff = new OutputFormatter()->format(new InlineDiff()->render("<p>one</p>\n<p>two</p>\n", "<p>one</p>\n<p>added</p>\n<p>two</p>\n"));
        self::assertNotNull($diff);
        self::assertStringContainsString('{+', $diff);
        self::assertStringContainsString('2 | 3', $diff);
        $deleted = new OutputFormatter()->format(new InlineDiff()->render("<p>one</p>\n<p>added</p>\n<p>two</p>\n", "<p>one</p>\n<p>two</p>\n"));
        self::assertNotNull($deleted);
        self::assertStringContainsString('[-', $deleted);
        self::assertStringContainsString('3 | 2', $deleted);
    }
}
