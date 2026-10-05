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
use ReflectionClass;
use RuntimeException;
use TYPO3Fluid\Fluid\Core\Parser\TemplateLocation;
use TYPO3Fluid\Fluid\Core\TemplateLocationException;
use TYPO3Fluid\Fluid\Validation\Deprecation;
use TYPO3Fluid\Fluid\Validation\TemplateValidatorResult;

final class FluidTemplateAnalyzerTest extends TestCase
{
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
}
