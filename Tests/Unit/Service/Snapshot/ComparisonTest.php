<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service\Snapshot;

use Andersundsehr\FrontendStudio\Service\Snapshot\Comparison;
use Andersundsehr\FrontendStudio\Service\Snapshot\HtmlFormatter;
use Andersundsehr\FrontendStudio\Service\Snapshot\Runner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ComparisonTest extends TestCase
{
    public function testWhitespaceRunsCompareAsOneButPresenceStillMatters(): void
    {
        $comparison = new Comparison();
        self::assertTrue($comparison->matches("<p>one \t\n\n two</p>\n", "<p>one two</p> "));
        self::assertTrue($comparison->matches('<pre>one  two</pre>', "<pre>one\ttwo</pre>"));
        self::assertFalse($comparison->matches('<p>one two</p>', '<p>onetwo</p>'));
        self::assertFalse($comparison->matches('<p>onetwo</p>', '<p>one two</p>'));
    }

    public function testWhitespaceLengthChangesDoNotCreateMarkers(): void
    {
        $first = "<p>one  two</p>\n\n";
        self::assertSame($first, new Comparison()->create($first, "<p>one\ttwo</p>\n"));
    }

    public function testWhitespaceChangesPreserveDynamicMarkerBoundaries(): void
    {
        $comparison = new Comparison();
        $expected = "<div>\n" . Comparison::MARKER . "\nhello world\n</div>\n";
        self::assertTrue($comparison->matches($expected, "<div>\n\nrandom value\nhello   world\n</div>\n"));
        self::assertFalse($comparison->matches($expected, "<div>\nrandom value\nextra\nhello world\n</div>\n"));
        self::assertFalse($comparison->matches($expected, "<div>\nrandom value\nhelloworld\n</div>\n"));
    }

    public function testSavedMarkersDoNotMaskNewStableChanges(): void
    {
        $comparison = new Comparison();
        $baseline = $comparison->create("<p id=one>\nhello\n</p>\n", "<p id=two>\nhello\n</p>\n");
        self::assertSame(Comparison::MARKER . "\nhello\n</p>\n", $baseline);
        self::assertTrue($comparison->matches($baseline, "<p id=three>\nhello\n</p>\n"));
        self::assertFalse($comparison->matches($baseline, "<p id=three>\nchanged\n</p>\n"));
        self::assertFalse($comparison->matches($baseline, "<p id=three>\nhello\nextra\n</p>\n"));
    }

    public function testMovedLinesCannotBecomeDynamicMarkers(): void
    {
        $this->expectException(RuntimeException::class);
        new Comparison()->create("a\nb\n", "b\na\n");
    }

    public function testInsertedLinesCannotBecomeDynamicMarkers(): void
    {
        $this->expectException(RuntimeException::class);
        new Comparison()->create("a\n", "a\nb\n");
    }

    public function testReservedMarkerInComponentFails(): void
    {
        $this->expectException(RuntimeException::class);
        new Comparison()->create(Comparison::MARKER, Comparison::MARKER);
    }

    public function testFormatterKeepsQuotedAnglesAndRawText(): void
    {
        $html = '<span title="a > b"> text </span><script>if (x < 1) { x = "<b>"; }</script><pre>  a\nb </pre><!-- <i> -->';
        $formatted = new HtmlFormatter()->format($html);
        self::assertSame('<span title="a > b"> text ' . "\n" . '</span>' . "\n" . '<script>if (x < 1) { x = "<b>"; }</script>' . "\n" . '<pre>  a\nb </pre>' . "\n" . '<!-- <i> -->' . "\n", $formatted);
    }

    public function testLongStartTagsSplitEveryAttribute(): void
    {
        $value = str_repeat('x', 70);
        self::assertSame('<span' . "\n  " . 'title="' . $value . '"' . "\n  disabled\n>hi\n</span>\n", new HtmlFormatter()->format('<span title="' . $value . '" disabled>hi</span>'));
    }

    public function testLongRawTextOpeningTagFormatsWithoutChangingItsContents(): void
    {
        $value = str_repeat('x', 70);
        $body = 'if (a > b) { alert("<span>"); }';
        self::assertSame('<script' . "\n  " . 'data-long="' . $value . '"' . "\n  defer\n>" . $body . "</script>\n", new HtmlFormatter()->format('<script data-long="' . $value . '" defer>' . $body . '</script>'));
    }

    public function testExitCodesDistinguishMissingAndErrors(): void
    {
        self::assertSame(0, Runner::exitCode([['status' => 'passed']]));
        self::assertSame(1, Runner::exitCode([]));
        self::assertSame(1, Runner::exitCode([['status' => 'error']]));
        self::assertSame(2, Runner::exitCode([['status' => 'missing']]));
        self::assertSame(3, Runner::exitCode([['status' => 'failed'], ['status' => 'missing']]));
    }
}
