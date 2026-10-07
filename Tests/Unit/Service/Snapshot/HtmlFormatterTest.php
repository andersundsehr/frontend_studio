<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service\Snapshot;

use Andersundsehr\FrontendStudio\Service\Snapshot\HtmlFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HtmlFormatterTest extends TestCase
{
    #[DataProvider('formattedFragments')]
    public function testFormatsHtmlWithoutChangingInlineOrRawContent(string $html, string $expected): void
    {
        $formatter = new HtmlFormatter();
        $formatted = $formatter->format($html);
        self::assertSame($expected, $formatted);
        self::assertSame($formatted, $formatter->format($formatted), 'Formatting an existing snapshot must not change it again.');
    }

    /** @return iterable<string, array{string, string}> */
    public static function formattedFragments(): iterable
    {
        yield 'nested block elements and aligned closing tags' => [
            '<div class="card"><section><h2>Title</h2><p>Content</p></section><footer>Footer</footer></div>',
            "<div class=\"card\">\n  <section>\n    <h2>Title</h2>\n    <p>Content</p>\n  </section>\n  <footer>Footer</footer>\n</div>\n",
        ];
        yield 'existing irregular structural indentation' => [
            "\n\t<div>\n       <section>\n <p>Content</p>\n    </section>\n </div>\n\n",
            "<div>\n  <section>\n    <p>Content</p>\n  </section>\n</div>\n",
        ];
        yield 'text-only element stays on one line' => ['<p>Short text</p>', "<p>Short text</p>\n"];
        yield 'empty element stays on one line' => ['<div></div>', "<div></div>\n"];
        yield 'whitespace-only leaf content remains present' => ['<p>  </p>', "<p>  </p>\n"];
        yield 'multiline leaf content remains unchanged' => ["<div><p>First\n  second</p></div>", "<div>\n  <p>First\n  second</p>\n</div>\n"];
        yield 'text whitespace and entities stay literal' => ['<p>  Café &amp; tea  </p>', "<p>  Café &amp; tea  </p>\n"];
        yield 'mixed text keeps inline element and punctuation' => ['<div><p>Hello <strong>world</strong>!</p></div>', "<div>\n  <p>Hello <strong>world</strong>!</p>\n</div>\n"];
        yield 'inline content does not gain spaces' => ['<p>A<em>B</em>C</p>', "<p>A<em>B</em>C</p>\n"];
        yield 'adjacent inline children do not gain spaces' => ['<p><strong>Hello</strong><em>world</em></p>', "<p><strong>Hello</strong><em>world</em></p>\n"];
        yield 'adjacent inline roots do not gain spaces' => ['<span>Hello</span><span>world</span>', "<span>Hello</span><span>world</span>\n"];
        yield 'root text and inline element stay together' => ['A<span>B</span>C', "A<span>B</span>C\n"];
        yield 'root text with a comparison angle stays literal' => ['A < B <span>C</span>', "A < B <span>C</span>\n"];
        yield 'existing spaces between inline roots stay present' => ['<span>Hello</span>  <span>world</span>', "<span>Hello</span>  <span>world</span>\n"];
        yield 'mixed content with a block child stays together' => ['<div>Before<section><p>Content</p></section>After</div>', "<div>Before<section><p>Content</p></section>After</div>\n"];
        yield 'multiple block roots' => ['<article><p>First</p></article><aside><p>Second</p></aside>', "<article>\n  <p>First</p>\n</article>\n<aside>\n  <p>Second</p>\n</aside>\n"];
        yield 'list nesting' => ['<ul><li>First</li><li><ul><li>Second</li></ul></li></ul>', "<ul>\n  <li>First</li>\n  <li>\n    <ul>\n      <li>Second</li>\n    </ul>\n  </li>\n</ul>\n"];
        yield 'table nesting' => ['<table><tbody><tr><td>Cell</td></tr></tbody></table>', "<table>\n  <tbody>\n    <tr>\n      <td>Cell</td>\n    </tr>\n  </tbody>\n</table>\n"];
        yield 'custom elements' => ['<card-panel><card-body>Content</card-body></card-panel>', "<card-panel>\n  <card-body>Content</card-body>\n</card-panel>\n"];
        yield 'case-insensitive HTML keeps its original tag case' => ['<DIV><Section><P>Content</p></SECTION></div>', "<DIV>\n  <Section>\n    <P>Content</p>\n  </SECTION>\n</div>\n"];
        yield 'SVG self-closing elements' => ['<svg><g><path d="M0 0"/><rect width="10" /></g></svg>', "<svg>\n  <g>\n    <path d=\"M0 0\"/>\n    <rect width=\"10\"/>\n  </g>\n</svg>\n"];
        yield 'SVG text and tspan stay inline' => ['<svg><text>Hello<tspan>world</tspan>!</text></svg>', "<svg>\n  <text>Hello<tspan>world</tspan>!</text>\n</svg>\n"];
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

        foreach (['script', 'style', 'pre', 'textarea'] as $tag) {
            $body = "\n\t  A < B\n  <span>literal</span>\n\n";
            yield $tag . ' body preserves all whitespace and apparent tags' => [
                '<div><' . $tag . '>' . $body . '</' . $tag . '><p>After</p></div>',
                "<div>\n  <" . $tag . '>' . $body . '</' . $tag . ">\n  <p>After</p>\n</div>\n",
            ];
        }

        foreach (['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'] as $tag) {
            $inner = in_array($tag, ['br', 'img', 'input', 'wbr'], true)
                ? '<section><' . $tag . '></section>'
                : "<section>\n    <" . $tag . ">\n  </section>";
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
            "<p>Hello <span\n    title=\"" . $value . "\"\n  >world</span>!</p>\n",
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
        yield 'an eighty-character opening tag stays on one line' => ['<p title="' . $value . '">Text</p>', '<p title="' . $value . '">Text</p>' . "\n"];
        yield 'indentation counts towards the opening tag width' => ['<div><p title="' . $value . '">Text</p></div>', "<div>\n  <p\n    title=\"" . $value . "\"\n  >Text</p>\n</div>\n"];
        $value = str_repeat('é', 68);
        yield 'opening tag width counts characters rather than UTF-8 bytes' => ['<p title="' . $value . '">Text</p>', '<p title="' . $value . '">Text</p>' . "\n"];
    }
}
