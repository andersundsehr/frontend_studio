<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service;

use Andersundsehr\FrontendStudio\Service\HtmlSourceHighlighter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HtmlSourceHighlighter::class)]
final class HtmlSourceHighlighterTest extends TestCase
{
    /** @param list<array{?string, string}> $tokens */
    #[DataProvider('sharedTokensDataProvider')]
    public function testFluidTokensMatchTheSharedJavaScriptFixtures(string $source, array $tokens): void
    {
        $highlighter = new HtmlSourceHighlighter();
        foreach ([$highlighter->highlightFluidUsage($source), $highlighter->highlightFluidTemplate($source)] as $html) {
            self::assertSame($source, html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            preg_match_all('/<span class="frontend-studio-variant-html-source__([^"]+)">(.*?)<\/span>|(\r?\n)/s', $html, $matches, PREG_SET_ORDER);
            $actual = [];
            foreach ($matches as $match) {
                $token = ($match[1] ?? '') !== '' ? $match[1] : null;
                $value = $token === null ? ($match[3] ?? '') : html_entity_decode($match[2] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($value === '') {
                    continue;
                }

                $last = array_key_last($actual);
                if ($last !== null && $actual[$last][0] === $token) {
                    $actual[$last][1] .= $value;
                } else {
                    $actual[] = [$token, $value];
                }
            }

            self::assertSame($tokens, $actual);
        }
    }

    /** @return iterable<string, array{string, list<array{?string, string}>}> */
    public static function sharedTokensDataProvider(): iterable
    {
        $fixtures = json_decode((string)file_get_contents(__DIR__ . '/Fixtures/fluid-highlighting.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($fixtures as $fixture) {
            yield $fixture['name'] => [$fixture['source'], $fixture['tokens']];
        }
    }

    #[DataProvider('usageDataProvider')]
    public function testUsageHighlightingPreservesSourceAndEscapesMarkup(string $source): void
    {
        $highlighted = new HtmlSourceHighlighter()->highlightFluidUsage($source);

        self::assertSame($source, html_entity_decode(strip_tags($highlighted), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        self::assertStringNotContainsString('<pre', $highlighted);
        self::assertStringNotContainsString('<code', $highlighted);
        self::assertStringNotContainsString('<ui:', $highlighted);
        self::assertStringNotContainsString('<script', $highlighted);
        self::assertStringNotContainsString('<f:fragment', $highlighted);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function usageDataProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'multiline attributes' => ["<ui:Card\n  title=\"First &amp; Second\"\n  enabled=\"{true}\"\n/>"];
        yield 'fragments' => ["<ui:Card>\n  <f:fragment name=\"footer\">\n    <strong>{title}</strong>\n  </f:fragment>\n</ui:Card>"];
        yield 'inline expression' => ["{ui:Card(title: 'It\\'s \\\\ready', enabled: true)}"];
        yield 'piped markup' => ["{f:format.raw(value: '<strong>Content</strong>') -> ui:Card(title: 'Hello')}"];
        yield 'Fluid escaped attributes' => ['<ui:Card title="A \"quote\" & <strong> \\path" />'];
        yield 'comment and script source' => ["<ui:Card>\n  <!-- Example -->\n  <script>alert('example')</script>\n</ui:Card>"];
        yield 'incomplete call' => ["{ui:Card(title: '<script>unfinished"];
        yield 'incomplete array' => ["{ui:Card(data: {title: 'Hello'}"];
        yield 'adjacent expressions' => ['Before {title}{ui:Card()} after'];
    }

    /**
     * @param list<array{string, string}> $tokens
     */
    #[DataProvider('inlineTokensDataProvider')]
    public function testHighlightsInlineTokensLikeTagSyntax(string $source, array $tokens): void
    {
        $highlighter = new HtmlSourceHighlighter();

        foreach ([$highlighter->highlightFluidUsage($source), $highlighter->highlightFluidTemplate($source)] as $highlighted) {
            self::assertSame($source, html_entity_decode(strip_tags($highlighted), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            foreach ($tokens as [$token, $value]) {
                self::assertStringContainsString(
                    '<span class="frontend-studio-variant-html-source__' . $token . '">'
                        . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>',
                    $highlighted,
                );
            }
        }
    }

    /**
     * @return iterable<string, array{string, list<array{string, string}>}>
     */
    public static function inlineTokensDataProvider(): iterable
    {
        yield 'empty call' => ['{ui:Card()}', [['tag', 'ui:Card'], ['punctuation', '{'], ['punctuation', '('], ['punctuation', ')'], ['punctuation', '}']]];
        yield 'typed arguments' => [
            "{ui:Card(title: 'Hello', body: content.body, enabled: true, count: -1.5, value: null)}",
            [['tag', 'ui:Card'], ['attribute', 'title'], ['string', "'Hello'"], ['fluid-expression', 'content.body'], ['fluid-expression', 'true'], ['fluid-expression', '-1.5'], ['fluid-expression', 'null'], ['punctuation', ':'], ['punctuation', ',']],
        ];
        yield 'chained calls without whitespace' => [
            '{content->f:format.raw()->ui:Card()}',
            [['fluid-expression', 'content'], ['tag', 'f:format.raw'], ['tag', 'ui:Card'], ['punctuation', '->']],
        ];
        yield 'nested arrays' => [
            "{ui:Card(data: {title: 'Hello', flags: {enabled: true}})}",
            [['tag', 'ui:Card'], ['attribute', 'data'], ['attribute', 'flags'], ['attribute', 'enabled'], ['fluid-expression', 'true']],
        ];
        yield 'quoted markup and braces' => [
            "{f:format.raw(value: '<strong>{title}</strong>') -> ui:Card()}",
            [['tag', 'f:format.raw'], ['tag', 'ui:Card'], ['string', "'<strong>{title}</strong>'"]],
        ];
        yield 'escaped quotes and backslashes' => [
            "{ui:Card(title: 'It\\'s \\\\ready }')}",
            [['tag', 'ui:Card'], ['string', "'It\\'s \\\\ready }'"]],
        ];
        yield 'double quoted string' => [
            '{ui:Card(title: "A \\"quote\\" {text}")}',
            [['tag', 'ui:Card'], ['string', '"A \\"quote\\" {text}"']],
        ];
        yield 'attribute value' => [
            '<div title="{ui:Card(title: \'Hello\')}"></div>',
            [['attribute', 'title'], ['tag', 'ui:Card'], ['string', "'Hello'"]],
        ];
        yield 'standalone attribute expression' => [
            '<div {enabled->f:if(then: \'hidden\')} id="content"></div>',
            [['tag', 'div'], ['fluid-expression', 'enabled'], ['tag', 'f:if'], ['attribute', 'then'], ['attribute', 'id'], ['string', "'hidden'"], ['string', '"content"']],
        ];
        yield 'multiline and unicode' => [
            "{ui:Card(\n  title: 'Café',\n  data: {key: value}\n)}",
            [['tag', 'ui:Card'], ['attribute', 'title'], ['string', "'Café'"], ['attribute', 'key'], ['fluid-expression', 'value']],
        ];
    }

    public function testPlainHtmlKeepsFluidCallsAsText(): void
    {
        $highlighted = new HtmlSourceHighlighter()->highlight('<div title="{ui:Card()}">{ui:Card()}</div>');

        self::assertStringContainsString('<span class="frontend-studio-variant-html-source__string">&quot;{ui:Card()}&quot;</span>', $highlighted);
        self::assertStringContainsString('<span class="frontend-studio-variant-html-source__text">{ui:Card()}</span>', $highlighted);
        self::assertStringNotContainsString('frontend-studio-variant-html-source__fluid-expression', $highlighted);
    }

    public function testTemplateAndHtmlHighlightingKeepTheirWrappers(): void
    {
        $highlighter = new HtmlSourceHighlighter();

        self::assertStringStartsWith('<pre class="frontend-studio-variant-html-source"><code>', $highlighter->highlight('<strong>Content</strong>'));
        self::assertStringContainsString('frontend-studio-variant-html-source__fluid-expression', $highlighter->highlightFluidTemplate('<strong>{title}</strong>'));
    }
}
