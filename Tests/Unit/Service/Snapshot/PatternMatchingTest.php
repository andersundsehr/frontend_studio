<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service\Snapshot;

use Andersundsehr\FrontendStudio\Service\Snapshot\Comparison;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PatternMatchingTest extends TestCase
{
    #[DataProvider('recognizedValues')]
    public function testRecognizedValuesPreserveSurroundingText(string $first, string $second, string $third): void
    {
        $comparison = new Comparison();
        foreach (['<p>A%sB</p>', '<div data-value="A%sB" class="stable">Hello</div>'] as $html) {
            $snapshot = $comparison->create(sprintf($html, $first), sprintf($html, $second));
            self::assertSame(sprintf($html, Comparison::MARKER), $snapshot);
            self::assertTrue($comparison->matches($snapshot, sprintf($html, $first)));
            self::assertTrue($comparison->matches($snapshot, sprintf($html, $second)));
            self::assertTrue($comparison->matches($snapshot, sprintf($html, $third)));
            self::assertFalse($comparison->matches($snapshot, str_replace('A', 'C', sprintf($html, $third))));
            self::assertFalse($comparison->matches($snapshot, str_replace('B', 'C', sprintf($html, $third))));
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function recognizedValues(): iterable
    {
        yield 'PHP date Y-M-D with microseconds' => ['2026-Oct-Tue 14:10:01.000001', '2026-Oct-Tue 14:10:02.000002', '2027-Jan-Mon 23:59:59.999999'];
        yield 'ISO date' => ['2026-10-06', '2026-10-07', '2027-01-01'];
        yield 'ISO date and time' => ['2026-10-06T14:10:01', '2026-10-06T14:10:02', '2027-01-01T23:59:59'];
        yield 'ISO UTC' => ['2026-10-06T14:10:01.123Z', '2026-10-06T14:10:02.456Z', '2027-01-01T00:00:00Z'];
        yield 'ISO offset' => ['2026-10-06T14:10:01+02:00', '2026-10-06T14:10:02+02:00', '2027-01-01T00:00:00-0500'];
        yield 'European dotted date' => ['06.10.2026', '07.10.2026', '01.01.2027'];
        yield 'European slash date' => ['06/10/2026', '07/10/2026', '01/01/2027'];
        yield 'US slash date' => ['10/06/2026', '10/07/2026', '12/31/2027'];
        yield 'European date and time' => ['06.10.2026 14:10:01', '06.10.2026 14:10:02', '01.01.2027 23:59:59'];
        yield 'named abbreviated month' => ['06 Oct 2026', '07 Oct 2026', '01 Jan 2027'];
        yield 'named full month' => ['06 October 2026', '07 October 2026', '01 January 2027'];
        yield 'US named month' => ['October 6, 2026', 'October 7, 2026', 'January 1, 2027'];
        yield 'ordinal named month' => ['October 6th, 2026', 'October 7th, 2026', 'January 1st, 2027'];
        yield 'named month and weekday' => ['2026-Oct-Tuesday', '2026-Oct-Wednesday', '2027-Jan-Monday'];
        yield 'hours and minutes' => ['14:10', '14:11', '23:59'];
        yield 'seconds' => ['14:10:01', '14:10:02', '00:00:00'];
        yield 'fractional seconds' => ['14:10:01.123456', '14:10:02.456789', '23:59:59.999999'];
        yield 'comma fractional seconds' => ['14:10:01,123', '14:10:02,456', '23:59:59,999'];
        yield 'AM PM' => ['02:10:01 PM', '02:10:02 PM', '11:59:59 AM'];
        yield 'UUID' => ['550e8400-e29b-41d4-a716-446655440000', '7f468241-3e02-4a20-bb29-321b81ed0951', '12345678-1234-1234-1234-123456789012'];
        yield 'numeric ID' => ['123', '124', '999999'];
    }

    #[DataProvider('snapshotCases')]
    public function testSnapshotInference(string $first, string $second, string $expected, string $third): void
    {
        $comparison = new Comparison();
        $snapshot = $comparison->create($first, $second);
        self::assertSame(str_replace('@', Comparison::MARKER, $expected), $snapshot);
        self::assertTrue($comparison->matches($snapshot, $first));
        self::assertTrue($comparison->matches($snapshot, $second));
        self::assertTrue($comparison->matches($snapshot, $third));
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function snapshotCases(): iterable
    {
        yield 'numeric ID prefix' => ['<div id="component-123">Hi</div>', '<div id="component-987">Hi</div>', '<div id="component-@">Hi</div>', '<div id="component-42">Hi</div>'];
        yield 'compact UUID' => ['<p>id:550e8400e29b41d4a716446655440000.</p>', '<p>id:7f4682413e024a20bb29321b81ed0951.</p>', '<p>id:@.</p>', '<p>id:0123456789abcdef0123456789abcdef.</p>'];
        yield 'ULID' => ['<p>id:01ARZ3NDEKTSV4RRFFQ69G5FAV.</p>', '<p>id:01ARZ3NDEKTSV4RRFFQ69G5FAW.</p>', '<p>id:@.</p>', '<p>id:01BX5ZZKBKACTAV9WEVGEMMVS0.</p>'];
        yield 'random hex' => ['<p>hash:abcdef0123456789.</p>', '<p>hash:abcdef012345678a.</p>', '<p>hash:@.</p>', '<p>hash:0123456789abcdefabcdef0123456789.</p>'];
        yield 'Unix seconds' => ['<div data-timestamp="time-1791270001"></div>', '<div data-timestamp="time-1791270002"></div>', '<div data-timestamp="time-@"></div>', '<div data-timestamp="time-1791270099"></div>'];
        yield 'Unix milliseconds' => ['<div data-time="1791270001123"></div>', '<div data-time="1791270002456"></div>', '<div data-time="@"></div>', '<div data-time="1791270009999"></div>'];
        yield 'URL token and fixed parameter' => ['<a href="/path?token=abc&amp;page=1">Link</a>', '<a href="/path?token=xyz&amp;page=1">Link</a>', '<a href="/path?token=@&amp;page=1">Link</a>', '<a href="/path?token=new-value&amp;page=1">Link</a>'];
        yield 'URL parameter containing UUID' => ['<a href="/path?token=550e8400-e29b-41d4-a716-446655440000#top">Link</a>', '<a href="/path?token=7f468241-3e02-4a20-bb29-321b81ed0951#top">Link</a>', '<a href="/path?token=@#top">Link</a>', '<a href="/path?token=other#top">Link</a>'];
        yield 'URL initially empty value' => ['<a href="/path?token=&amp;page=1">Link</a>', '<a href="/path?token=xyz&amp;page=1">Link</a>', '<a href="/path?token=@&amp;page=1">Link</a>', '<a href="/path?token=&amp;page=1">Link</a>'];
        yield 'unknown whole attribute' => ['<div data-token="abc-Xq-zz" class="card">Hello</div>', '<div data-token="abc-Kz-ww" class="card">Hello</div>', '<div data-token="@" class="card">Hello</div>', '<div data-token="entirely different with spaces" class="card">Hello</div>'];
        yield 'empty attribute becomes nonempty' => ['<div title="">Hello</div>', '<div title="random">Hello</div>', '<div title="@">Hello</div>', '<div title="">Hello</div>'];
        yield 'single quoted attribute' => ["<div title='alpha'>Hi</div>", "<div title='omega'>Hi</div>", "<div title='@'>Hi</div>", "<div title='any words'>Hi</div>"];
        yield 'unquoted attribute' => ['<input value=abc/>', '<input value=xyz/>', '<input value=@/>', '<input value=other/>'];
        yield 'mixed known and unknown attribute falls back whole' => ['<div data-token="id-123-alpha"></div>', '<div data-token="id-456-omega"></div>', '<div data-token="@"></div>', '<div data-token="other"></div>'];
        yield 'two values in text' => ['<p>At 14:10:01; request 123; status ready</p>', '<p>At 14:10:02; request 456; status ready</p>', '<p>At @; request @; status ready</p>', '<p>At 23:59:59; request 999999; status ready</p>'];
        yield 'unknown text preserves surrounding label' => ['<p>Token: abcXdef; status ready</p>', '<p>Token: abcYdef; status ready</p>', '<p>Token: abc@def; status ready</p>', '<p>Token: abcANYdef; status ready</p>'];
        yield 'Unicode text fallback' => ['<p>Grüße: Käse, fertig</p>', '<p>Grüße: Küse, fertig</p>', '<p>Grüße: K@se, fertig</p>', '<p>Grüße: KÖse, fertig</p>'];
        yield 'unchanged recognized patterns stay literal' => ['<p>2026-10-06 id=123</p>', '<p>2026-10-06 id=123</p>', '<p>2026-10-06 id=123</p>', '<p>2026-10-06 id=123</p>'];
        yield 'unchanged date beside changing ID stays literal' => ['<p>2026-10-06 id=123</p>', '<p>2026-10-06 id=456</p>', '<p>2026-10-06 id=@</p>', '<p>2026-10-06 id=999</p>'];
        yield 'lowercase named months' => ['<p>A06 oct 2026 14:10:01B</p>', '<p>A06 oct 2026 14:10:02B</p>', '<p>A@B</p>', '<p>A01 jan 2027 23:59:59B</p>'];
        yield 'text insertion preserves surrounding words' => ['<p>AfooB</p>', '<p>AfooxyzB</p>', '<p>Afoo@B</p>', '<p>AfooanythingB</p>'];
        yield 'text deletion preserves surrounding words' => ['<p>AfooxyzB</p>', '<p>AfooB</p>', '<p>Afoo@B</p>', '<p>AfooanythingB</p>'];
        yield 'empty text becomes nonempty' => ['<p></p>', '<p>xyz</p>', '<p>@</p>', '<p></p>'];
        yield 'nonempty text becomes empty' => ['<p>xyz</p>', '<p></p>', '<p>@</p>', '<p>anything</p>'];
        yield 'namespaced attribute' => ['<svg xlink:href="/path?token=abc"></svg>', '<svg xlink:href="/path?token=xyz"></svg>', '<svg xlink:href="/path?token=@"></svg>', '<svg xlink:href="/path?token=new"></svg>'];
        yield 'whitespace only changes stay literal' => ["<p>same \t words</p>\n", "<p>same words</p>\n\n", "<p>same \t words</p>\n", "<p>same\nwords</p>\n"];
    }

    #[DataProvider('matchingCases')]
    public function testReviewedSnapshotMatching(string $snapshot, string $actual, bool $matches): void
    {
        self::assertSame($matches, new Comparison()->matches(str_replace('@', Comparison::MARKER, $snapshot), $actual));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function matchingCases(): iterable
    {
        yield 'date marker may include spaces' => ['<p>A@B</p>', '<p>A2027-Jan-Mon 23:59:59.999999B</p>', true];
        yield 'empty text marker' => ['<p>A@B</p>', '<p>AB</p>', true];
        yield 'entire text marker matches empty node' => ['<p>@</p>', '<p></p>', true];
        yield 'leading text marker matches empty node' => ['@<p>fixed</p>', '<p>fixed</p>', true];
        yield 'trailing text marker matches empty node' => ['<p>fixed</p>@', '<p>fixed</p>', true];
        yield 'plain text marker matches empty output' => ['@', '', true];
        yield 'empty full text marker cannot swallow element' => ['<p>@</p>', '<p><b>bad</b></p>', false];
        yield 'stable prefix changed' => ['<p>A@B</p>', '<p>CvalueB</p>', false];
        yield 'stable suffix changed' => ['<p>A@B</p>', '<p>AvalueC</p>', false];
        yield 'marker cannot swallow element' => ['<p>A@B</p>', '<p>A<b>value</b>B</p>', false];
        yield 'marker cannot swallow comment' => ['<p>A@B</p>', '<p>A<!-- x -->B</p>', false];
        yield 'whole attribute with whitespace' => ['<p title="@">Hi</p>', '<p title="many words">Hi</p>', true];
        yield 'attribute cannot swallow another attribute' => ['<p title="@">Hi</p>', '<p title="value" class="new">Hi</p>', false];
        yield 'attribute must exist' => ['<p title="@">Hi</p>', '<p>Hi</p>', false];
        yield 'attribute name fixed' => ['<p title="@">Hi</p>', '<p data-title="value">Hi</p>', false];
        yield 'neighboring attribute checked' => ['<p title="@" class="stable">Hi</p>', '<p title="value" class="changed">Hi</p>', false];
        yield 'tag name checked' => ['<p title="@">Hi</p>', '<div title="value">Hi</div>', false];
        yield 'neighboring text checked' => ['<p title="@">Hi</p>', '<p title="value">Bye</p>', false];
        yield 'unquoted value cannot consume whitespace' => ['<input value=@/>', '<input value=one extra=two/>', false];
        yield 'unquoted close fixed' => ['<input value=@/>', '<input value=one>', false];
        yield 'unchanged date checked' => ['<p>2026-10-06</p>', '<p>2026-10-07</p>', false];
        yield 'static whitespace presence checked' => ['<p>a b</p>', '<p>ab</p>', false];
        yield 'regex punctuation literal' => ['<p>(A.@+$)[fixed]</p>', '<p>(A.value+$)[fixed]</p>', true];
        yield 'actual cannot contain reserved marker' => ['<p>@</p>', '<p>{{frontend-studio:dynamic}}</p>', false];
        yield 'URL path checked' => ['<a href="/path?token=@&amp;page=1">Link</a>', '<a href="/other?token=abc&amp;page=1">Link</a>', false];
        yield 'URL fixed parameter checked' => ['<a href="/path?token=@&amp;page=1">Link</a>', '<a href="/path?token=abc&amp;page=2">Link</a>', false];
        yield 'URL marker cannot consume another parameter' => ['<a href="/path?token=@&amp;page=1">Link</a>', '<a href="/path?token=abc&amp;page=2&amp;page=1">Link</a>', false];
        yield 'URL final marker cannot consume fragment' => ['<a href="/path?token=@">Link</a>', '<a href="/path?token=abc#new">Link</a>', false];
        yield 'multiple markers preserve middle anchor' => ['<p>A@ middle @B</p>', '<p>Aone changed twoB</p>', false];
        yield 'quoted attribute with escaped quotes' => ['<p title="@">Hi</p>', '<p title="one &quot;two&quot;">Hi</p>', true];
        yield 'quoted attribute greater than character' => ['<p title="@">Hi</p>', '<p title="one > two">Hi</p>', true];
        yield 'different quote syntax fixed' => ['<p title="@">Hi</p>', "<p title='one'>Hi</p>", false];
        yield 'unchanged date beside dynamic value checked' => ['<p>2026-10-06 id=@</p>', '<p>2026-10-07 id=999</p>', false];
        yield 'marker across text whitespace' => ['<p>A@B</p>', "<p>Aone\ntwoB</p>", true];
        yield 'Unicode anchor is fixed' => ['<p>Grüße: @ fertig</p>', '<p>Grusse: value fertig</p>', false];
    }

    #[DataProvider('structuralChanges')]
    public function testStructuralChangesDoNotBecomeWildcards(string $first, string $second): void
    {
        $this->expectException(RuntimeException::class);
        new Comparison()->create($first, $second);
    }

    /** @return iterable<string, array{string, string}> */
    public static function structuralChanges(): iterable
    {
        yield 'tag name' => ['<p>same</p>', '<div>same</div>'];
        yield 'attribute name' => ['<p id="a">same</p>', '<p title="b">same</p>'];
        yield 'new attribute' => ['<p id="a">same</p>', '<p id="b" class="new">same</p>'];
        yield 'inserted tag' => ['<p>same</p>', '<p><b>same</b></p>'];
        yield 'changed comment' => ['<!-- first --><p>same</p>', '<!-- second --><p>same</p>'];
        yield 'changed doctype' => ['<!DOCTYPE html><p>same</p>', '<!DOCTYPE other><p>same</p>'];
        yield 'reserved new marker' => ['<p>same</p>', '<p>{{frontend-studio:dynamic}}</p>'];
        yield 'reserved legacy marker' => ['<p>same</p>', '<!-- frontend-studio:dynamic-line -->'];
        yield 'invalid encoding' => ["<p>\xFF</p>", '<p>same</p>'];
    }
}
