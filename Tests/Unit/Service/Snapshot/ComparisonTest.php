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
    #[DataProvider('dynamicDiffSamples')]
    public function testDiffMasksCurrentDynamicValuesAcrossSnapshotChanges(string $baseline, string $first, string $second, string $masked): void
    {
        $comparison = new Comparison();
        self::assertFalse($comparison->matches($baseline, $first));
        self::assertSame($masked, $comparison->maskForDiff($baseline, $first, $second));
        self::assertSame($masked, $comparison->maskForDiff($baseline, $masked));
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function dynamicDiffSamples(): iterable
    {
        $marker = Comparison::MARKER;
        $firstDate = '2026-Oct-Wed 15:10:16.000000';
        $secondDate = '2027-Nov-Mon 16:11:17.000000';
        yield 'line break splits a saved dynamic text node' => [
            "<div>\n  TestA" . $marker . "B\n</div>\n",
            "<div>\n  Test<br>\n  A" . $firstDate . "B\n</div>\n",
            "<div>\n  Test<br>\n  A" . $secondDate . "B\n</div>\n",
            "<div>\n  Test<br>\n  A" . $marker . "B\n</div>\n",
        ];
        yield 'new tag wraps a saved dynamic value' => [
            '<p>A' . $marker . 'B</p>',
            '<p><time>A' . $firstDate . 'B</time></p>',
            '<p><time>A' . $secondDate . 'B</time></p>',
            '<p><time>A' . $marker . 'B</time></p>',
        ];
        yield 'tag name changes without changing token count' => [
            '<p>A' . $marker . 'B</p>',
            '<div>A' . $firstDate . 'B</div>',
            '<div>A' . $secondDate . 'B</div>',
            '<div>A' . $marker . 'B</div>',
        ];
        yield 'new dynamic attribute is absent from the baseline' => [
            '<p>Stable</p>',
            '<p title="A' . $firstDate . 'B">Stable</p>',
            '<p title="A' . $secondDate . 'B">Stable</p>',
            '<p title="A' . $marker . 'B">Stable</p>',
        ];
        yield 'new UUID stays inside its attribute after structural changes' => [
            '<p>Stable</p>',
            '<div id="card-550e8400-e29b-41d4-a716-446655440000">Stable</div>',
            '<div id="card-550e8400-e29b-41d4-a716-446655440001">Stable</div>',
            '<div id="card-' . $marker . '">Stable</div>',
        ];
        yield 'unknown dynamic attribute uses the full value' => [
            '<p>Stable</p>',
            '<p data-token="prefix-abcXdef-suffix">Stable</p>',
            '<p data-token="prefix-abcYdef-suffix">Stable</p>',
            '<p data-token="' . $marker . '">Stable</p>',
        ];
        yield 'unknown dynamic text keeps labels after a tag change' => [
            '<p>Token: ' . $marker . '; status ready</p>',
            '<div>Token: abcXdef; status ready</div>',
            '<div>Token: abcYdef; status ready</div>',
            '<div>Token: ' . $marker . '; status ready</div>',
        ];
        yield 'stable dates remain visible beside a changing date' => [
            '<p>Old</p>',
            '<p>Published: 2025-01-01; now: ' . $firstDate . '</p>',
            '<p>Published: 2025-01-01; now: ' . $secondDate . '</p>',
            '<p>Published: 2025-01-01; now: ' . $marker . '</p>',
        ];
        yield 'changed stable text is retained beside the marker' => [
            '<p>Old A' . $marker . 'B</p>',
            '<p>New A' . $firstDate . 'B</p>',
            '<p>New A' . $secondDate . 'B</p>',
            '<p>New A' . $marker . 'B</p>',
        ];
        yield 'manual markers still mask stable values in matching structures' => [
            '<p id="' . $marker . '">Old</p>',
            '<p id="custom-token">New</p>',
            '<p id="custom-token">New</p>',
            '<p id="' . $marker . '">New</p>',
        ];
        yield 'stable output does not acquire markers' => [
            '<p>Old</p>', '<div>New 2026-10-07</div>', '<div>New 2026-10-07</div>', '<div>New 2026-10-07</div>',
        ];
        yield 'inserted markup between current samples stays visible' => [
            '<p>Old</p>', '<div>First</div>', '<div>First<br>Second</div>', '<div>First</div>',
        ];
        yield 'changed tag between current samples stays visible' => [
            '<p>Old</p>', '<div>First</div>', '<section>Second</section>', '<div>First</div>',
        ];
        yield 'changed attribute name between current samples stays visible' => [
            '<p>Old</p>', '<div title="First">New</div>', '<div class="Second">New</div>', '<div title="First">New</div>',
        ];
        yield 'inserted text line between current samples stays visible' => [
            '<p>Old</p>', "<p>First\n</p>", "<p>First\nSecond\n</p>", "<p>First\n</p>",
        ];
        yield 'moved text lines between current samples stay visible' => [
            '<p>Old</p>', "<p>First\nSecond\n</p>", "<p>Second\nFirst\n</p>", "<p>First\nSecond\n</p>",
        ];
    }

    public function testDiffDoesNotHideReservedMarkerExceptions(): void
    {
        $this->expectExceptionMessage('Rendered output contains a reserved dynamic marker.');
        new Comparison()->maskForDiff('<p>Old</p>', '<p>' . Comparison::MARKER . '</p>', '<p>Second</p>');
    }

    public function testWhitespaceRunsCompareAsOneButPresenceStillMatters(): void
    {
        $comparison = new Comparison();
        self::assertTrue($comparison->matches("<p>one \t\n\n two</p>\n", "<p>one two</p> "));
        self::assertTrue($comparison->matches('<pre>one  two</pre>', "<pre>one\ttwo</pre>"));
        self::assertFalse($comparison->matches('<p>one two</p>', '<p>onetwo</p>'));
        self::assertFalse($comparison->matches('<p>onetwo</p>', '<p>one two</p>'));
    }

    #[DataProvider('inlineWhitespace')]
    public function testFormattingPreservesWhitespacePresence(string $withoutSpace, string $withSpace, string $withWhitespaceRun): void
    {
        $formatter = new HtmlFormatter();
        $comparison = new Comparison();
        $baseline = $formatter->format($withoutSpace);
        $actual = $formatter->format($withSpace);
        $multiple = $formatter->format($withWhitespaceRun);
        self::assertFalse($comparison->matches($baseline, $actual), 'Adding original whitespace must fail.');
        self::assertFalse($comparison->matches($actual, $baseline), 'Removing original whitespace must fail.');
        self::assertTrue($comparison->matches($actual, $multiple), 'Original whitespace runs still compare as one space.');
        self::assertTrue($comparison->matches($multiple, $actual));
        self::assertTrue($comparison->matches($withoutSpace, $baseline), 'Presentation whitespace must not change the original HTML.');
        self::assertTrue($comparison->matches($withSpace, $actual));
        foreach ([$baseline, $actual, $multiple] as $formatted) {
            self::assertSame($formatted, $formatter->format($formatted));
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function inlineWhitespace(): iterable
    {
        foreach ([' ', "\n", "\r\n", "\t\n\r\n  "] as $whitespace) {
            yield 'before opening inline tag ' . json_encode($whitespace) => [
                '<p>Hello<strong>world</strong></p>', '<p>Hello <strong>world</strong></p>',
                '<p>Hello' . $whitespace . '<strong>world</strong></p>',
            ];
            yield 'between adjacent inline elements ' . json_encode($whitespace) => [
                '<strong>Hello</strong><em>world</em>', '<strong>Hello</strong> <em>world</em>',
                '<strong>Hello</strong>' . $whitespace . '<em>world</em>',
            ];
            yield 'before closing inline tag ' . json_encode($whitespace) => [
                '<p><strong>Hello</strong>world</p>', '<p><strong>Hello </strong>world</p>',
                '<p><strong>Hello' . $whitespace . '</strong>world</p>',
            ];
            yield 'after closing inline tag ' . json_encode($whitespace) => [
                '<p><strong>Hello</strong>world</p>', '<p><strong>Hello</strong> world</p>',
                '<p><strong>Hello</strong>' . $whitespace . 'world</p>',
            ];
            yield 'empty inline content ' . json_encode($whitespace) => [
                '<span></span>', '<span> </span>', '<span>' . $whitespace . '</span>',
            ];
        }
    }

    public function testLongTagLayoutAndDynamicMarkersPreserveOriginalWhitespace(): void
    {
        $formatter = new HtmlFormatter();
        $comparison = new Comparison();
        $prefix = '<p title="' . str_repeat('long', 25) . '" disabled>Hello';
        $first = $formatter->format($prefix . ' <strong id="c123">world</strong></p>');
        $second = $formatter->format($prefix . ' <strong id="c124">world</strong></p>');
        self::assertTrue($comparison->matches($prefix . ' <strong id="c123">world</strong></p>', $first));
        $baseline = $comparison->create($first, $second);
        self::assertStringStartsWith(HtmlFormatter::HEADER, $baseline);
        self::assertStringContainsString('id="c' . Comparison::MARKER . '"', $baseline);
        self::assertSame($baseline, $formatter->format($baseline));
        self::assertTrue($comparison->matches($baseline, $formatter->format($prefix . "\t\n<strong id=\"c125\">world</strong></p>")));
        $withoutSpace = $formatter->format($prefix . '<strong id="c125">world</strong></p>');
        self::assertFalse($comparison->matches($baseline, $withoutSpace));
        $masked = $comparison->maskForDiff($baseline, $withoutSpace, $formatter->format($prefix . '<strong id="c126">world</strong></p>'));
        self::assertStringStartsWith(HtmlFormatter::HEADER, $masked);
        self::assertSame($formatter->format($prefix . '<strong id="c' . Comparison::MARKER . '">world</strong></p>'), $masked);
        self::assertStringNotContainsString('125', $masked);
        self::assertSame($masked, $formatter->format($masked));
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
        self::assertSame(HtmlFormatter::HEADER . '<span title="a > b"> text ' . "\n</span>\n"
            . '<script>if (x < 1) { x = "<b>"; }</script>' . "\n"
            . '<pre>  a\\nb </pre>' . "\n<!-- <i> -->\n", $formatted);
    }

    public function testLongStartTagsSplitEveryAttribute(): void
    {
        $value = str_repeat('x', 70);
        self::assertSame(HtmlFormatter::HEADER . '<span' . "\n  " . 'title="' . $value . '"' . "\n  disabled\n>hi\n</span>\n", new HtmlFormatter()->format('<span title="' . $value . '" disabled>hi</span>'));
    }

    public function testLongRawTextOpeningTagFormatsWithoutChangingItsContents(): void
    {
        $value = str_repeat('x', 70);
        $body = 'if (a > b) { alert("<span>"); }';
        self::assertSame(HtmlFormatter::HEADER . '<script' . "\n  " . 'data-long="' . $value . '"' . "\n  defer\n>" . $body . "</script>\n", new HtmlFormatter()->format('<script data-long="' . $value . '" defer>' . $body . '</script>'));
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
