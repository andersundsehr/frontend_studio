<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service;

use DOMNameSpaceNode;
use DOMNode;
use Andersundsehr\FrontendStudio\Service\FluidTemplateAnalyzer;
use Andersundsehr\FrontendStudio\Service\HtmlSourceHighlighter;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;
use TYPO3Fluid\Fluid\Core\Parser\TemplateLocation;
use TYPO3Fluid\Fluid\Core\Parser\Exception as ParserException;
use TYPO3Fluid\Fluid\Core\TemplateLocationException;
use TYPO3Fluid\Fluid\Validation\Deprecation;
use TYPO3Fluid\Fluid\Validation\TemplateValidatorResult;

final class FluidTemplateAnalyzerTest extends TestCase
{
    public function testParserContextIsRemovedFromMessageWithoutLosingTheLocation(): void
    {
        $original = new ParserException('Required argument "type" was not supplied.', 1237823699);
        $error = new ParserException(
            'Fluid parse error in template /template.html, line 1 at character 5. Error: Required argument "type" was not supplied. (error code 1237823699). Template source chunk: <f:argument name="bodytext"/>',
            $original->getCode(),
            $original,
            new TemplateLocation('/template.html', 1, 5),
        );
        $analyzer = new ReflectionClass(FluidTemplateAnalyzer::class)->newInstanceWithoutConstructor();
        $diagnostics = $analyzer->diagnostics(new TemplateValidatorResult('id', '/template.html', [$error], [], null), '    <f:argument name="bodytext"/>');
        self::assertSame([
            'line' => 1,
            'character' => 5,
            'severity' => 'error',
            'message' => 'Required argument "type" was not supplied. (error code 1237823699).',
        ], $diagnostics[0]);
        $html = new HtmlSourceHighlighter()->highlightFluidDiagnostics('    <f:argument name="bodytext"/>', $diagnostics);
        self::assertStringContainsString('Error: Required argument &quot;type&quot; was not supplied. (error code 1237823699).', $html);
        self::assertStringContainsString('data-character="5"', $html);
        self::assertStringNotContainsString('/template.html', $html);
        self::assertStringNotContainsString('Template source chunk:', $html);
    }

    public function testOnlyLocationsWithinTheDisplayedTemplateAreAnnotated(): void
    {
        $errors = [new RuntimeException('Unlocated <script>')];
        foreach ([['/template.html', 2], ['/other.html', 1], ['/template.html', 0], ['/template.html', 4]] as [$path, $line]) {
            $errors[] = new class ($path, $line) extends RuntimeException implements TemplateLocationException {
                public function __construct(private readonly string $path, private readonly int $templateLine)
                {
                    parent::__construct('Bad <f:if>');
                }

                public function getTemplateLocation(): TemplateLocation
                {
                    return new TemplateLocation($this->path, $this->templateLine, 1);
                }
            };
        }

        $result = new TemplateValidatorResult('id', '/template.html', $errors, [new Deprecation('/php.php', 2, 'Deprecated')], null);
        $analyzer = new ReflectionClass(FluidTemplateAnalyzer::class)->newInstanceWithoutConstructor();
        $diagnostics = $analyzer->diagnostics($result, "one\ntwo\n");
        self::assertSame([null, 2, null, null, null, null], array_column($diagnostics, 'line'));
        self::assertSame([null, 1, null, null, null, null], array_column($diagnostics, 'character'));
        self::assertSame('deprecation', $diagnostics[5]['severity']);
    }

    public function testMultilineTokensBlankLinesUnicodeAndEscapingSurviveAnnotation(): void
    {
        $source = "<!-- first\r\nsecond -->\r\n\r\n<div title=\"ä\r\n世界\">{value}</div>\r\n";
        $html = new HtmlSourceHighlighter()->highlightFluidDiagnostics($source, [
            ['line' => 2, 'severity' => 'error', 'message' => '<script>alert(1)</script>'],
            ['line' => 2, 'severity' => 'error', 'message' => 'Second error'],
            ['line' => null, 'severity' => 'deprecation', 'message' => '<img onerror="bad">'],
        ]);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);

        $xpath = new DOMXPath($dom);
        $code = $xpath->query('//code');
        self::assertNotFalse($code);
        self::assertCount(6, $code);
        self::assertSame(str_replace("\r\n", "\n", $source), implode("\n", array_map(static function (DOMNode|DOMNameSpaceNode $node): string {
            self::assertInstanceOf(DOMNode::class, $node);
            return rtrim($node->textContent, "\r");
        }, iterator_to_array($code))));
        self::assertSame(2.0, $xpath->evaluate('count(//*[@data-line="2"]/*[contains(@class,"diagnostic")])'));
        self::assertSame(1.0, $xpath->evaluate('count(//*[@data-line="2"]/code/*[contains(@class,"__comment")])'));
        self::assertSame(1.0, $xpath->evaluate('count(//*[@data-line="5"]/code/*[contains(@class,"__string")])'));
    }

    #[DataProvider('markerPositions')]
    public function testMarkerPreservesTheExactSourcePrefixAndHighlightedText(string $source, int $line, string $prefix): void
    {
        $character = strlen($prefix) + 1;
        $error = new class ($line, $character) extends RuntimeException implements TemplateLocationException {
            public function __construct(private readonly int $templateLine, private readonly int $character)
            {
                parent::__construct('Bad <f:for> & "argument"');
            }

            public function getTemplateLocation(): TemplateLocation
            {
                return new TemplateLocation('/template.html', $this->templateLine, $this->character);
            }
        };
        $analyzer = new ReflectionClass(FluidTemplateAnalyzer::class)->newInstanceWithoutConstructor();
        $diagnostics = $analyzer->diagnostics(new TemplateValidatorResult('id', '/template.html', [$error], [], null), $source);
        self::assertSame($character, $diagnostics[0]['character']);
        $html = new HtmlSourceHighlighter()->highlightFluidDiagnostics($source, $diagnostics);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);

        $xpath = new DOMXPath($dom);
        $markers = $xpath->query('//span[@class="frontend-studio-template-marker"]');
        self::assertNotFalse($markers);
        self::assertCount(1, $markers);
        $marker = $markers->item(0);
        self::assertInstanceOf(DOMNode::class, $marker);
        self::assertSame((string)$character, $xpath->evaluate('string(@data-character)', $marker));
        $preceding = $xpath->query('preceding::text()[ancestor::code/parent::span[@data-line="' . $line . '"]]', $marker);
        self::assertNotFalse($preceding);
        self::assertSame($prefix, implode('', array_map(static function (DOMNode|DOMNameSpaceNode $node): string {
            self::assertInstanceOf(DOMNode::class, $node);
            return $node->textContent;
        }, iterator_to_array($preceding))));
        $code = $xpath->query('//code');
        self::assertNotFalse($code);
        self::assertSame(str_replace("\r\n", "\n", $source), implode("\n", array_map(static function (DOMNode|DOMNameSpaceNode $node): string {
            self::assertInstanceOf(DOMNode::class, $node);
            return rtrim($node->textContent, "\r");
        }, iterator_to_array($code))));
        self::assertSame('Error: Bad <f:for> & "argument"', $xpath->evaluate('string(//span[contains(@class,"template-diagnostic")])'));
        self::assertSame('', $marker->textContent, 'The marker must not change copied source text.');
        self::assertStringNotContainsString('<f:for>', $html);
        if ($source !== '') {
            self::assertGreaterThan(0, $xpath->evaluate('count(//code/span[starts-with(@class,"frontend-studio-variant-html-source__")])'));
        }
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function markerPositions(): iterable
    {
        yield 'tabs' => ["\t\t<f:for as=\"item\" />", 1, "\t\t"];
        yield 'Unicode byte position' => ['ä世界😀 <f:for as="item" />', 1, 'ä世界😀 '];
        yield 'CRLF' => ["first\r\n\t<f:for as=\"item\" />\r\n", 2, "\t"];
        yield 'escaped HTML in string token' => ['a & <div title="<bad>">', 1, 'a & <div title="'];
        yield 'inside tag token' => ['<f:for as="item" />', 1, '<f:'];
        yield 'Unicode inside string token' => ['<div title="ä世界">', 1, '<div title="ä'];
        yield 'inside Fluid token' => ['{foo -> f:format.raw()}', 1, '{foo -> f:for'];
        yield 'multiline comment' => ["<!-- first\r\nä\t& second -->", 2, "ä\t& "];
        yield 'end of line' => ['<p>ok</p>', 1, '<p>ok</p>'];
        yield 'empty source' => ['', 1, ''];
    }

    public function testUnavailableCharactersKeepLineOnlyAndUnlocatedDiagnostics(): void
    {
        $diagnostics = [];
        foreach ([null, 0, -1, 2, 99] as $character) {
            $diagnostics[] = ['line' => 1, 'character' => $character, 'severity' => 'error', 'message' => 'Line-only'];
        }

        $diagnostics[] = ['line' => null, 'character' => 1, 'severity' => 'error', 'message' => 'Unlocated'];
        $html = new HtmlSourceHighlighter()->highlightFluidDiagnostics('ä', $diagnostics);
        self::assertStringNotContainsString('frontend-studio-template-marker', $html);
        self::assertStringContainsString('is-error" data-line="1"', $html);
        self::assertSame(6, substr_count($html, 'frontend-studio-template-diagnostic'));
        self::assertStringContainsString('frontend-studio-template-summary', $html);
    }

    public function testMultipleDiagnosticsShareACaretAtTheSamePosition(): void
    {
        $html = new HtmlSourceHighlighter()->highlightFluidDiagnostics('abcd', [
            ['line' => 1, 'character' => 3, 'severity' => 'error', 'message' => 'First'],
            ['line' => 1, 'character' => 1, 'severity' => 'error', 'message' => 'Second'],
            ['line' => 1, 'character' => 3, 'severity' => 'error', 'message' => 'Third'],
        ]);
        self::assertSame(2, substr_count($html, 'frontend-studio-template-marker'));
        self::assertSame(3, substr_count($html, 'frontend-studio-template-diagnostic'));
    }
}
