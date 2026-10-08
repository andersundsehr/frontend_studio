<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service\Snapshot;

use Andersundsehr\FrontendStudio\Service\Snapshot\HtmlFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HtmlFormatterTest extends TestCase
{
    #[DataProvider('originalFragments')]
    public function testRecoversOriginalWhitespaceWithoutTouchingRawContents(string $html): void
    {
        $formatter = new HtmlFormatter();
        self::assertSame($html, $formatter->original($html), 'Literal HTML has no formatter layout to remove.');
        $formatted = $formatter->format($html);
        self::assertSame($html, $formatter->original($formatted));
        self::assertSame($formatted, $formatter->format($formatted));
    }

    /** @return iterable<string, array{string}> */
    public static function originalFragments(): iterable
    {
        yield 'text touching an inline tag' => ['<p>Hello<strong>world</strong></p>'];
        yield 'space before an inline tag' => ['<p>Hello <strong>world</strong></p>'];
        yield 'literal newline before an inline tag' => ["<p>Hello\n<strong>world</strong></p>"];
        yield 'adjacent inline tags' => ['<strong>Hello</strong><em>world</em>'];
        yield 'multiple spaces between inline tags' => ['<strong>Hello</strong>  <em>world</em>'];
        yield 'tabs and CRLF between inline tags' => ["<strong>Hello</strong>\t\r\n \n<em>world</em>"];
        yield 'whitespace before and after the fragment' => ["\n\t<p>text</p>\n\n "];
        yield 'whitespace-only content' => ["<span>\t\r\n  </span>"];
        yield 'literal comparison angles and entities' => ['<p>A < B &amp; C&#32;<span>D</span></p>'];
        yield 'comments and CDATA' => ["<!-- first\n <tag> -->\n<svg><![CDATA[<x> ]]></svg>"];
        yield 'malformed fragment stays literal' => ["<div><p>text\n\t</div>\n"];
        yield 'plain text keeps final newlines' => ["text\n\n"];
        yield 'empty content' => [''];
        yield 'attribute whitespace and self-closing padding' => ["<div\t title = 'a > b'\r\n hidden   ><img src=x \t/></div>\r\n"];
        yield 'long attributes preserve their original separators' => ['<p title="' . str_repeat('x', 90) . '"  hidden>Text</p>'];
        foreach (['pre', 'textarea', 'script', 'style'] as $tag) {
            yield $tag . ' contents remain exact' => ["<div><" . $tag . ">\n\t<span>raw</span>\n  </" . $tag . "></div>\n"];
        }
    }

    #[DataProvider('cleanFormatting')]
    public function testReusesExistingLineBreaksWithExactRoundTrip(string $html, string $expected): void
    {
        $formatter = new HtmlFormatter();
        $formatted = $formatter->format($html);
        self::assertSame(HtmlFormatter::HEADER . $expected, preg_replace('~<!-- frontend-studio:snapshot-whitespace:[A-Za-z0-9+/=]+ -->\n$~', '', $formatted));
        self::assertSame($html, $formatter->original($formatted));
        self::assertSame($formatted, $formatter->format($formatted));
        self::assertSame($formatted, $formatter->format($html));
    }

    /** @return iterable<string, array{string, string}> */
    public static function cleanFormatting(): iterable
    {
        $clean = "<div>\n  <p>Hello</p>\n</div>\n";
        yield 'already indented HTML' => ["<div>\n  <p>Hello</p>\n</div>", $clean];
        yield 'minified HTML' => ['<div><p>Hello</p></div>', $clean];
        yield 'mixed original and inserted layout' => ["<div>\n\t<p>Hello</p></div>\r\n", $clean];
        yield 'CRLF and tabs' => ["<div>\r\n\t\t<p>Hello</p>\r\n</div>\r\n", $clean];
        yield 'already formatted closing tags' => [$clean, $clean];
        yield 'intentional blank lines' => ["<div>\n\t\n  <p>Hello</p>\n \t\n</div>\n\n", "<div>\n\n  <p>Hello</p>\n\n</div>\n\n"];
        yield 'text before an existing tag line break' => ["<p>Hello \t\r\n    <strong>world</strong>!</p>", "<p>Hello\n  <strong>world</strong>!</p>\n"];
        yield 'space stays distinct from no space' => ['<p>Hello <strong>world</strong></p>', "<p>Hello\n  <strong>world</strong></p>\n"];
        yield 'no space before inline tag' => ['<p>Hello<strong>world</strong></p>', "<p>Hello<strong>world</strong></p>\n"];
        yield 'existing break between inline roots' => ["<strong>Hello</strong>\r\n \t<em>world</em>", "<strong>Hello</strong>\n<em>world</em>\n"];
        yield 'leading indentation without a break' => [" \t <p>Hello</p>", "<p>Hello</p>\n"];
        foreach (['pre', 'textarea', 'script', 'style'] as $tag) {
            $raw = "\r\n\t\tA < B\r\n\r\n  last";
            yield $tag . ' preserves raw contents while reusing surrounding layout' => [
                "<div>\r\n\t<" . $tag . '>' . $raw . '</' . $tag . ">\r\n  <p>Hello</p>\r\n</div>\r\n",
                "<div>\n  <" . $tag . '>' . $raw . '</' . $tag . ">\n  <p>Hello</p>\n</div>\n",
            ];
        }
    }

    #[DataProvider('textBoundaries')]
    public function testFormatsContainerTextAndPreservesSensitiveBoundaries(string $html, string $expected): void
    {
        $formatter = new HtmlFormatter();
        $formatted = $formatter->format($html);
        self::assertSame(HtmlFormatter::HEADER . $expected, preg_replace('~<!-- frontend-studio:snapshot-whitespace:[A-Za-z0-9+/=]+ -->\n$~', '', $formatted));
        self::assertSame($html, $formatter->original($formatted));
        self::assertSame($formatted, $formatter->format($formatted));
        self::assertSame($formatted, $formatter->format($html));
    }

    /** @return iterable<string, array{string, string}> */
    public static function textBoundaries(): iterable
    {
        foreach (['div', 'section', 'article', 'aside', 'header', 'footer', 'main', 'nav'] as $tag) {
            foreach (['', ' ', "\t", "\r\n  "] as $space) {
                yield $tag . ' content with ' . json_encode($space) => [
                    '<' . $tag . '>' . $space . 'Left' . $space . '</' . $tag . '>',
                    '<' . $tag . ">\n  Left\n</" . $tag . ">\n",
                ];
            }
        }

        yield 'container text around a child' => ['<div>Before<section><p>Content</p></section>After</div>', "<div>\n  Before\n  <section>\n    <p>Content</p>\n  </section>\n  After\n</div>\n"];
        yield 'nested container text indentation' => ['<div><section>Left</section></div>', "<div>\n  <section>\n    Left\n  </section>\n</div>\n"];
        yield 'long container tag and separate text' => ['<div title="' . str_repeat('x', 90) . '">Left</div>', "<div\n  title=\"" . str_repeat('x', 90) . "\"\n>\n  Left\n</div>\n"];
        yield 'empty container avoids a blank content line' => ['<div></div>', "<div>\n</div>\n"];

        foreach (['p', 'span', 'strong', 'em', 'a', 'h2', 'li', 'td', 'text', 'tspan', 'custom-inline'] as $tag) {
            yield $tag . ' no surrounding whitespace' => ['<' . $tag . '>Hello</' . $tag . '>', '<' . $tag . '>Hello</' . $tag . ">\n"];
            yield $tag . ' leading space only' => ['<' . $tag . '> Hello</' . $tag . '>', '<' . $tag . ">\n  Hello</" . $tag . ">\n"];
            yield $tag . ' trailing space only' => ['<' . $tag . '>Hello </' . $tag . '>', '<' . $tag . ">Hello\n</" . $tag . ">\n"];
            yield $tag . ' space at both ends' => ['<' . $tag . '> Hello </' . $tag . '>', '<' . $tag . ">\n  Hello\n</" . $tag . ">\n"];
        }

        foreach (["\t", "\n", "\r\n", " \t\r\n  "] as $space) {
            yield 'sensitive text reuses ' . json_encode($space) => ['<p>' . $space . 'Hello' . $space . '</p>', "<p>\n  Hello\n</p>\n"];
        }

        yield 'intentional blank lines around text' => ["<div>\r\n\r\n\tLeft\r\n\r\n</div>\r\n", "<div>\n\n  Left\n\n</div>\n"];
        yield 'sensitive blank lines remain visible' => ["<p>\n\n  Hello\n\n</p>", "<p>\n\n  Hello\n\n</p>\n"];
        yield 'nonbreaking space entity remains literal' => ['<p>&nbsp;Hello&nbsp;</p>', "<p>&nbsp;Hello&nbsp;</p>\n"];
        yield 'literal comparison angle in container text' => ['<div>A < B</div>', "<div>\n  A < B\n</div>\n"];
        yield 'closing inline tag touches punctuation' => ['<p>Hello <strong>world</strong>!</p>', "<p>Hello\n  <strong>world</strong>!</p>\n"];
        yield 'adjacent inline tags without spaces' => ['<strong>Hello</strong><em>world</em>', "<strong>Hello</strong><em>world</em>\n"];
        yield 'space after inline tag' => ['<p><strong>Hello</strong> world</p>', "<p><strong>Hello</strong>\n  world</p>\n"];
        foreach (['pre', 'textarea', 'script', 'style'] as $tag) {
            foreach (['raw', " \t\r\n<span>raw</span>\r\n\r\n "] as $body) {
                yield $tag . ' raw boundary ' . json_encode($body) => ['<div><' . $tag . '>' . $body . '</' . $tag . '></div>', "<div>\n  <" . $tag . '>' . $body . '</' . $tag . ">\n</div>\n"];
            }
        }
    }

    public function testEditingTextAndDynamicValuesDoesNotInvalidateWhitespaceRecovery(): void
    {
        $formatter = new HtmlFormatter();
        $html = "<div>\r\n\tBefore<section><p title=\"" . str_repeat('x', 90) . "\"  hidden> Hello </p></section>After</div>\r\n";
        $formatted = $formatter->format($html);
        $edited = str_replace(['Hello', str_repeat('x', 90)], ['Hello world', '{{frontend-studio:dynamic}}'], $formatted);
        self::assertSame(str_replace(['Hello', str_repeat('x', 90)], ['Hello world', '{{frontend-studio:dynamic}}'], $html), $formatter->original($edited));
        self::assertSame($formatter->format($formatter->original($edited)), $formatter->format($edited));
    }

    public function testPreviousSnapshotLayoutRemainsRecoverable(): void
    {
        $formatter = new HtmlFormatter();
        $previous = HtmlFormatter::HEADER . "<div>\n  <p>Hello\n  </p>\n</div>\n";
        self::assertSame('<div><p>Hello</p></div>', $formatter->original($previous));
        self::assertSame($formatter->format('<div><p>Hello</p></div>'), $formatter->format($previous));
        $whitespace = ['tags' => [1 => ['before' => "\r\n\t"], 3 => ['before' => "\r\n"]], 'end' => "\r\n"];
        $previous .= '<!-- frontend-studio:snapshot-whitespace:' . base64_encode(json_encode($whitespace, JSON_THROW_ON_ERROR)) . " -->\n";
        $original = "<div>\r\n\t<p>Hello</p>\r\n</div>\r\n";
        self::assertSame($original, $formatter->original($previous));
        self::assertSame($formatter->format($original), $formatter->format($previous));
    }

    #[DataProvider('completeLineWrapping')]
    public function testWrapsAttributesUsingTheCompleteDisplayLine(string $html, string $expected): void
    {
        $formatter = new HtmlFormatter();
        $formatted = $formatter->format($html);
        self::assertSame(HtmlFormatter::HEADER . $expected, preg_replace('~<!-- frontend-studio:snapshot-whitespace:[A-Za-z0-9+/=]+ -->\n$~', '', $formatted));
        self::assertSame($html, $formatter->original($formatted));
        self::assertSame($formatted, $formatter->format($formatted));
        self::assertSame($formatted, $formatter->format($html));
    }

    /** @return iterable<string, array{string, string}> */
    public static function completeLineWrapping(): iterable
    {
        $heading = '<h1 class="text-heading-3 font-bold text-text">Semantic H1 with H3 sizing</h1>';
        yield 'heading below eighty characters' => [$heading, $heading . "\n"];
        yield 'heading exactly eighty characters with indentation' => ['<div>' . $heading . '</div>', "<div>\n  " . $heading . "\n</div>\n"];
        yield 'heading exceeding eighty characters with indentation' => ['<div><section>' . $heading . '</section></div>', "<div>\n  <section>\n    <h1\n      class=\"text-heading-3 font-bold text-text\"\n    >Semantic H1 with H3 sizing</h1>\n  </section>\n</div>\n"];
        $opening = '<p class="card">';
        $text = str_repeat('x', 80 - mb_strlen($opening . '</p>'));
        yield 'complete line exactly eighty characters' => [$opening . $text . '</p>', $opening . $text . "</p>\n"];
        yield 'closing tag pushes the complete line beyond eighty characters' => [$opening . $text . 'x</p>', "<p\n  class=\"card\"\n>" . $text . "x</p>\n"];
        $unicode = str_repeat('é', mb_strlen($text));
        yield 'complete line counts Unicode characters rather than bytes' => [$opening . $unicode . '</p>', $opening . $unicode . "</p>\n"];
        yield 'long text on a separate line does not wrap a short opening tag' => [$opening . ' ' . str_repeat('x', 100) . '</p>', $opening . "\n  " . str_repeat('x', 100) . "</p>\n"];
        yield 'container text on a separate line does not wrap a short opening tag' => ['<div class="card">' . str_repeat('x', 100) . '</div>', "<div class=\"card\">\n  " . str_repeat('x', 100) . "\n</div>\n"];
        yield 'long text without attributes remains literal' => ['<p>' . str_repeat('x', 100) . '</p>', '<p>' . str_repeat('x', 100) . "</p>\n"];
        yield 'long content splits every attribute and preserves original gaps' => ["<p\tclass='card'  hidden data-id=x>" . str_repeat('x', 60) . '</p>', "<p\n  class='card'\n  hidden\n  data-id=x\n>" . str_repeat('x', 60) . "</p>\n"];
        yield 'inline prefix counts towards an attributed tag line' => [str_repeat('x', 65) . '<span title="x">text</span>', str_repeat('x', 65) . "<span\n  title=\"x\"\n>text</span>\n"];
        yield 'self-closing terminator retains its element indentation' => ['<p>' . str_repeat('x', 60) . '<img alt="image"/>tail</p>', '<p>' . str_repeat('x', 60) . "<img\n    alt=\"image\"\n  />tail</p>\n"];
        yield 'inline descendants count towards the parent line' => [$opening . '<strong>' . str_repeat('x', 60) . '</strong></p>', "<p\n  class=\"card\"\n><strong>" . str_repeat('x', 60) . "</strong></p>\n"];
        yield 'a following line does not count towards the opening tag line' => [$opening . "First\n" . str_repeat('x', 100) . '</p>', $opening . "First\n" . str_repeat('x', 100) . "</p>\n"];
        foreach (['pre', 'textarea', 'script', 'style'] as $tag) {
            $body = str_repeat('x', 90) . "\r\n\t<span>literal</span>\r\n  ";
            yield $tag . ' long first line preserves its exact body' => ['<div><' . $tag . ' class="card">' . $body . '</' . $tag . '></div>', "<div>\n  <" . $tag . "\n    class=\"card\"\n  >" . $body . '</' . $tag . ">\n</div>\n"];
            $body = "\r" . str_repeat('x', 90) . "\r  ";
            yield $tag . ' first CR line break ends the width measurement' => ['<' . $tag . ' class="card">' . $body . '</' . $tag . '>', '<' . $tag . ' class="card">' . $body . '</' . $tag . ">\n"];
        }
    }

    #[DataProvider('formattedFragments')]
    public function testFormatsContainersAndSensitiveTextWithoutChangingRawContent(string $html, string $expected): void
    {
        $formatter = new HtmlFormatter();
        $formatted = $formatter->format($html);
        self::assertSame(HtmlFormatter::HEADER . $expected, preg_replace('~<!-- frontend-studio:snapshot-whitespace:[A-Za-z0-9+/=]+ -->\n$~', '', $formatted));
        self::assertSame($html, $formatter->original($formatted), 'Readable layout must preserve the exact original source.');
        self::assertSame($formatted, $formatter->format($formatted), 'Formatting an existing snapshot must not change it again.');
    }

    /** @return iterable<string, array{string, string}> */
    public static function formattedFragments(): iterable
    {
        yield 'nested block elements and aligned closing tags' => [
            '<div class="card"><section><h2>Title</h2><p>Content</p></section><footer>Footer</footer></div>',
            "<div class=\"card\">\n  <section>\n    <h2>Title</h2>\n    <p>Content</p>\n  </section>\n  <footer>\n    Footer\n  </footer>\n</div>\n",
        ];
        yield 'existing irregular structural indentation' => [
            "\n\t<div>\n       <section>\n <p>Content</p>\n    </section>\n </div>\n\n",
            "\n<div>\n  <section>\n    <p>Content</p>\n  </section>\n</div>\n\n",
        ];
        yield 'text-only element keeps its touching closing tag' => ['<p>Short text</p>', "<p>Short text</p>\n"];
        yield 'empty element has a separate closing tag' => ['<div></div>', "<div>\n</div>\n"];
        yield 'whitespace-only leaf content remains recoverable' => ['<p>  </p>', "<p>\n</p>\n"];
        yield 'multiline leaf content remains unchanged' => ["<div><p>First\n  second</p></div>", "<div>\n  <p>First\n  second</p>\n</div>\n"];
        yield 'text whitespace and entities stay recoverable' => ['<p>  Café &amp; tea  </p>', "<p>\n  Café &amp; tea\n</p>\n"];
        yield 'mixed text splits at spaces and retains punctuation' => ['<div><p>Hello <strong>world</strong>!</p></div>', "<div>\n  <p>Hello\n    <strong>world</strong>!</p>\n</div>\n"];
        yield 'inline content keeps touching text segments together' => ['<p>A<em>B</em>C</p>', "<p>A<em>B</em>C</p>\n"];
        yield 'adjacent inline children without spaces stay together' => ['<p><strong>Hello</strong><em>world</em></p>', "<p><strong>Hello</strong><em>world</em></p>\n"];
        yield 'adjacent inline roots without spaces stay together' => ['<span>Hello</span><span>world</span>', "<span>Hello</span><span>world</span>\n"];
        yield 'root text and touching inline tags stay together' => ['A<span>B</span>C', "A<span>B</span>C\n"];
        yield 'root text with a comparison angle stays literal' => ['A < B <span>C</span>', "A < B\n<span>C</span>\n"];
        yield 'existing spaces between inline roots allow a break' => ['<span>Hello</span>  <span>world</span>', "<span>Hello</span>\n<span>world</span>\n"];
        yield 'mixed content with a block child splits tags' => ['<div>Before<section><p>Content</p></section>After</div>', "<div>\n  Before\n  <section>\n    <p>Content</p>\n  </section>\n  After\n</div>\n"];
        yield 'multiple block roots' => ['<article><p>First</p></article><aside><p>Second</p></aside>', "<article>\n  <p>First</p>\n</article>\n<aside>\n  <p>Second</p>\n</aside>\n"];
        yield 'list nesting' => ['<ul><li>First</li><li><ul><li>Second</li></ul></li></ul>', "<ul>\n  <li>First</li>\n  <li>\n    <ul>\n      <li>Second</li>\n    </ul></li>\n</ul>\n"];
        yield 'table nesting' => ['<table><tbody><tr><td>Cell</td></tr></tbody></table>', "<table>\n  <tbody>\n    <tr>\n      <td>Cell</td>\n    </tr>\n  </tbody>\n</table>\n"];
        yield 'custom elements default to sensitive boundaries' => ['<card-panel><card-body>Content</card-body></card-panel>', "<card-panel><card-body>Content</card-body></card-panel>\n"];
        yield 'case-insensitive HTML keeps its original tag case' => ['<DIV><Section><P>Content</p></SECTION></div>', "<DIV>\n  <Section>\n    <P>Content</p>\n  </SECTION>\n</div>\n"];
        yield 'SVG self-closing elements' => ['<svg><g><path d="M0 0"/><rect width="10" /></g></svg>', "<svg>\n  <g>\n    <path d=\"M0 0\"/>\n    <rect width=\"10\"/>\n  </g>\n</svg>\n"];
        yield 'SVG text and tspan tags preserve touching boundaries' => ['<svg><text>Hello<tspan>world</tspan>!</text></svg>', "<svg>\n  <text>Hello<tspan>world</tspan>!</text>\n</svg>\n"];
        yield 'namespaced SVG elements' => ['<svg><svg:g><svg:path/></svg:g></svg>', "<svg>\n  <svg:g>\n    <svg:path/>\n  </svg:g>\n</svg>\n"];
        yield 'CDATA stays opaque inside SVG text' => ['<svg><text><![CDATA[A<B & C]]></text></svg>', "<svg>\n  <text><![CDATA[A<B & C]]></text>\n</svg>\n"];
        yield 'comments are indented without interpreting their tags' => ["<div><!-- <i> -->\n<section><!-- note --><p>Content</p></section></div>", "<div>\n  <!-- <i> -->\n  <section>\n    <!-- note -->\n    <p>Content</p>\n  </section>\n</div>\n"];
        yield 'multiline comments retain their contents' => ["<div><!-- first\n <tag> second --><p>Text</p></div>", "<div>\n  <!-- first\n <tag> second -->\n  <p>Text</p>\n</div>\n"];
        yield 'doctype stays outside the document indentation' => ['<!DOCTYPE html><html><body><p>Content</p></body></html>', "<!DOCTYPE html>\n<html>\n  <body>\n    <p>Content</p>\n  </body>\n</html>\n"];
        yield 'quoted angles are not tag boundaries' => ['<div><p title="a > b and < c">Text</p></div>', "<div>\n  <p title=\"a > b and < c\">Text</p>\n</div>\n"];
        yield 'single-quoted attributes and value whitespace remain literal' => ["<div><p title='a > b  < c'>Text</p></div>", "<div>\n  <p title='a > b  < c'>Text</p>\n</div>\n"];
        yield 'already split short opening tag is canonicalized' => ["<div>\n  <p\n    class=\"card\"\n  >Text</p>\n</div>\n", "<div>\n  <p class=\"card\">Text</p>\n</div>\n"];
        yield 'mismatched closing tags are not repaired' => ['<div><p>Text</div>', "<div><p>Text</div>\n"];
        yield 'omitted closing tags are not inferred' => ['<ul><li>First<li>Second</ul>', "<ul><li>First<li>Second</ul>\n"];
        yield 'unclosed fragment stays literal' => ['<div><p>Text', "<div><p>Text\n"];
        yield 'unexpected closing tag stays literal' => ['</div><p>Text</p>', "</div><p>Text</p>\n"];
        yield 'plain text' => ['Content', "Content\n"];
        yield 'empty fragment has one final newline' => ['', "\n"];

        foreach (['span', 'strong', 'em', 'a', 'b', 'small', 'label', 'custom-inline'] as $tag) {
            yield $tag . ' boundaries split only where whitespace exists' => [
                '<p>Before <' . $tag . '>inside</' . $tag . '> after</p>',
                '<p>Before' . "\n  <" . $tag . '>inside</' . $tag . ">\n  after</p>\n",
            ];
        }

        foreach (['script', 'style', 'pre', 'textarea'] as $tag) {
            yield $tag . ' closing tag stays beside its final raw content' => [
                '<div><' . $tag . '>first' . "\n" . '  last</' . $tag . '></div>',
                "<div>\n  <" . $tag . '>first' . "\n" . '  last</' . $tag . ">\n</div>\n",
            ];
            $body = "\n\t  A < B\n  <span>literal</span>\n\n";
            yield $tag . ' body preserves all whitespace and apparent tags' => [
                '<div><' . $tag . '>' . $body . '</' . $tag . '><p>After</p></div>',
                "<div>\n  <" . $tag . '>' . $body . '</' . $tag . ">\n  <p>After</p>\n</div>\n",
            ];
        }

        foreach (['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'] as $tag) {
            $inner = "<section>\n    <" . $tag . ">\n  </section>";
            yield $tag . ' does not increase the depth of following siblings' => [
                '<div><section><' . $tag . '></section><p>After</p></div>',
                "<div>\n  " . $inner . "\n  <p>After</p>\n</div>\n",
            ];
        }

        $value = str_repeat('x', 70);
        yield 'long attributes follow the element indentation' => [
            '<div><section><p title="' . $value . '" disabled>Text</p></section></div>',
            "<div>\n  <section>\n    <p\n      title=\"" . $value . "\"\n      disabled\n    >Text</p>\n  </section>\n</div>\n",
        ];
        yield 'long attributes in mixed content stay inside the opening tag' => [
            '<p>Hello <span title="' . $value . '">world</span>!</p>',
            "<p>Hello\n  <span\n    title=\"" . $value . "\"\n  >world</span>!</p>\n",
        ];
        yield 'long self-closing tag keeps its terminator aligned' => [
            '<svg><path data-long="' . $value . '" d="M0 0"/></svg>',
            "<svg>\n  <path\n    data-long=\"" . $value . "\"\n    d=\"M0 0\"\n  />\n</svg>\n",
        ];
        yield 'long tag splits boolean unquoted and quoted attributes' => [
            '<div><p title="' . $value . '" hidden data-value=token data-label=\'a > b\'>Text</p></div>',
            "<div>\n  <p\n    title=\"" . $value . "\"\n    hidden\n    data-value=token\n    data-label='a > b'\n  >Text</p>\n</div>\n",
        ];
        foreach (['script', 'style', 'pre', 'textarea'] as $tag) {
            $body = "\n\t  A < B\n  literal\n";
            yield $tag . ' long opening tag does not change its body' => [
                '<div><' . $tag . ' data-long="' . $value . '">' . $body . '</' . $tag . '></div>',
                "<div>\n  <" . $tag . "\n    data-long=\"" . $value . "\"\n  >" . $body . '</' . $tag . ">\n</div>\n",
            ];
        }

        $value = str_repeat('x', 68);
        yield 'an eighty-character opening tag wraps when content extends the line' => ['<p title="' . $value . '">Text</p>', "<p\n  title=\"" . $value . "\"\n>Text</p>\n"];
        yield 'indentation counts towards the opening tag width' => ['<div><p title="' . $value . '">Text</p></div>', "<div>\n  <p\n    title=\"" . $value . "\"\n  >Text</p>\n</div>\n"];
        $value = str_repeat('é', 68);
        yield 'Unicode opening tag wraps when content extends the line' => ['<p title="' . $value . '">Text</p>', "<p\n  title=\"" . $value . "\"\n>Text</p>\n"];
    }
}
