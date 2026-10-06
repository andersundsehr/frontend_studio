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
        $diff = new InlineDiff()->render('<p>Grüße: old</p><error>text</error>', '<p>Grüße: new</p><error>text</error>');
        self::assertStringContainsString('<fg=red>[-old-]</>', $diff);
        self::assertStringContainsString('<fg=green>{+new+}</>', $diff);
        $plain = new OutputFormatter()->format($diff);
        self::assertNotNull($plain);
        self::assertStringContainsString('Grüße: [-old-]{+new+}', $plain);
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

    public function testSavedDynamicLinesAreExcludedFromStableDiffs(): void
    {
        $diff = new InlineDiff()->render(Comparison::MARKER . "\n<p>old</p>\n", "random value\n<p>new</p>\n");
        self::assertStringContainsString('[-old-]', $diff);
        self::assertStringContainsString('{+new+}', $diff);
        self::assertStringNotContainsString('random value', $diff);
    }

    public function testLongUnchangedSectionsAreCollapsed(): void
    {
        $start = str_repeat('<p>unchanged</p>', 20);
        $diff = new InlineDiff()->render($start . '<p>old</p>', $start . '<p>new</p>');
        self::assertStringContainsString('…', $diff);
        self::assertLessThan(6, substr_count($diff, 'unchanged'));
        self::assertStringContainsString('[-old-]', $diff);
    }
}
