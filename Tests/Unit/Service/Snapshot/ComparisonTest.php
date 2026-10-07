<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service\Snapshot;

use Andersundsehr\FrontendStudio\Service\Snapshot\Comparison;
use Andersundsehr\FrontendStudio\Service\Snapshot\HtmlFormatter;
use Andersundsehr\FrontendStudio\Service\Snapshot\Runner;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testWhitespaceChangesPreserveInlineMarkerBoundaries(): void
    {
        $comparison = new Comparison();
        $expected = '<div id="' . Comparison::MARKER . '" class="stable">' . "\nhello world\n</div>\n";
        self::assertTrue($comparison->matches($expected, '<div id="random" class="stable">' . "\n\nhello   world\n</div>\n"));
        self::assertFalse($comparison->matches($expected, '<div id="random" class="changed">' . "\nhello world\n</div>\n"));
        self::assertFalse($comparison->matches($expected, '<div id="random" class="stable">' . "\nhelloworld\n</div>\n"));
    }

    public function testSavedMarkersDoNotMaskNewStableChanges(): void
    {
        $comparison = new Comparison();
        $baseline = $comparison->create("<p id=one>\nhello\n</p>\n", "<p id=two>\nhello\n</p>\n");
        self::assertSame('<p id=' . Comparison::MARKER . ">\nhello\n</p>\n", $baseline);
        self::assertTrue($comparison->matches($baseline, "<p id=three>\nhello\n</p>\n"));
        self::assertFalse($comparison->matches($baseline, "<p id=three>\nchanged\n</p>\n"));
        self::assertFalse($comparison->matches($baseline, "<p id=three>\nhello\nextra\n</p>\n"));
    }

    public function testMovedLinesCannotBecomeDynamicMarkers(): void
    {
        $this->expectException(RuntimeException::class);
        new Comparison()->create("a\nb\n", "b\na\n");
    }

    public function testMultipleInlineValuesKeepStableAttributesAndTextChecked(): void
    {
        $comparison = new Comparison();
        $baseline = $comparison->create('<p id="c123" class="stable">Token abc123, status ready</p>', '<p id="c124" class="stable">Token abc124, status ready</p>');
        self::assertSame('<p id="c' . Comparison::MARKER . '" class="stable">Token abc' . Comparison::MARKER . ', status ready</p>', $baseline);
        self::assertTrue($comparison->matches($baseline, '<p id="c999999" class="stable">Token abc999999, status ready</p>'));
        self::assertFalse($comparison->matches($baseline, '<p id="c999" class="changed">Token abc999, status ready</p>'));
        self::assertFalse($comparison->matches($baseline, '<p id="c999" class="stable">Token abc999, status broken</p>'));
        self::assertFalse($comparison->matches($baseline, '<p id="c999" class="stable">Token abc999, extra status ready</p>'));
        self::assertFalse($comparison->matches($baseline, '<p id="c999" class="stable">Token <b>injected</b> status ready</p>'));
    }

    public function testChangingTagNamesCannotBecomeDynamicMarkers(): void
    {
        $this->expectException(RuntimeException::class);
        new Comparison()->create('<p>same</p>', '<div>same</div>');
    }

    public function testInlineMarkersCannotConsumeUnquotedAttributeBoundaries(): void
    {
        $comparison = new Comparison();
        $baseline = $comparison->create('<input value=123/>', '<input value=124/>');
        self::assertSame('<input value=' . Comparison::MARKER . '/>', $baseline);
        self::assertTrue($comparison->matches($baseline, '<input value=99999/>'));
        self::assertFalse($comparison->matches($baseline, '<input value=99999>'));
        self::assertFalse($comparison->matches($baseline, '<input value=99999 extra=bad/>'));
    }

    public function testLegacyMarkersRequireExplicitRegeneration(): void
    {
        $this->expectExceptionMessage('Regenerate this snapshot with --update');
        new Comparison()->matches('<!-- frontend-studio:dynamic-line -->', 'anything');
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

    public function testInvalidUtf8CannotSilentlyMatch(): void
    {
        $this->expectExceptionMessage('Snapshot HTML is not valid UTF-8');
        new Comparison()->matches("<p>\xFF</p>", "<p>\xFE</p>");
    }

    public function testFormatterKeepsQuotedAnglesAndRawText(): void
    {
        $html = '<span title="a > b"> text </span><script>if (x < 1) { x = "<b>"; }</script><pre>  a\nb </pre><!-- <i> -->';
        $formatted = new HtmlFormatter()->format($html);
        self::assertSame($html . "\n", $formatted);
    }

    public function testLongStartTagsSplitEveryAttribute(): void
    {
        $value = str_repeat('x', 70);
        self::assertSame('<span' . "\n  " . 'title="' . $value . '"' . "\n  disabled\n>hi</span>\n", new HtmlFormatter()->format('<span title="' . $value . '" disabled>hi</span>'));
    }

    public function testLongRawTextOpeningTagFormatsWithoutChangingItsContents(): void
    {
        $value = str_repeat('x', 70);
        $body = 'if (a > b) { alert("<span>"); }';
        self::assertSame('<script' . "\n  " . 'data-long="' . $value . '"' . "\n  defer\n>" . $body . "</script>\n", new HtmlFormatter()->format('<script data-long="' . $value . '" defer>' . $body . '</script>'));
    }

    /** @param list<array{status: string}> $results */
    #[DataProvider('exitCodes')]
    public function testExitCodesDistinguishFileChangesAndErrors(array $results, int $expected): void
    {
        self::assertSame($expected, Runner::exitCode($results));
    }

    /** @return iterable<string, array{list<array{status: string}>, int}> */
    public static function exitCodes(): iterable
    {
        yield 'unchanged snapshots' => [[['status' => 'passed']], 0];
        yield 'empty scope' => [[], 1];
        yield 'render error' => [[['status' => 'error']], 1];
        yield 'mismatch' => [[['status' => 'failed']], 1];
        yield 'missing snapshot' => [[['status' => 'missing']], 2];
        yield 'updated snapshot' => [[['status' => 'updated']], 2];
        yield 'updated and unchanged' => [[['status' => 'updated'], ['status' => 'passed']], 2];
        yield 'updated and missing' => [[['status' => 'updated'], ['status' => 'missing']], 2];
        yield 'updated and render error' => [[['status' => 'updated'], ['status' => 'error']], 3];
        yield 'updated and mismatch' => [[['status' => 'updated'], ['status' => 'failed']], 3];
        yield 'missing and mismatch' => [[['status' => 'missing'], ['status' => 'failed']], 3];
        yield 'updated missing and error' => [[['status' => 'updated'], ['status' => 'missing'], ['status' => 'error']], 3];
    }
}
