<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service;

use Andersundsehr\FrontendStudio\Dto\ComponentFixtureMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentTemplateMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantValueMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantValues;
use Andersundsehr\FrontendStudio\Service\FluidUsageSnippetRenderer;
use Andersundsehr\FrontendStudio\Service\HtmlSourceHighlighter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TYPO3Fluid\Fluid\Core\Variables\StandardVariableProvider;

#[CoversClass(ComponentMetadata::class)]
#[CoversClass(ComponentFixtureMetadata::class)]
#[CoversClass(ComponentVariantMetadata::class)]
#[CoversClass(ComponentVariantValueMetadata::class)]
#[CoversClass(ComponentVariantValues::class)]
#[CoversClass(FluidUsageSnippetRenderer::class)]
#[CoversClass(HtmlSourceHighlighter::class)]
final class FluidUsageSnippetRendererTest extends TestCase
{
    public function testBuildsFluidUsageFromTypedFixtureValues(): void
    {
        $metadata = $this->createMetadata();

        $snippet = new FluidUsageSnippetRenderer(new HtmlSourceHighlighter())->build($metadata);

        self::assertSame('<ui:Card title="Hello" enabled="{true}" />', $snippet);
        self::assertSame(
            "{ui:Card(title: 'Hello', enabled: true)}",
            new FluidUsageSnippetRenderer(new HtmlSourceHighlighter())->buildInline($metadata),
        );
    }

    public function testExposesReadonlyPropertiesToFluid(): void
    {
        $variables = new StandardVariableProvider(['metadata' => $this->createMetadata()]);

        self::assertSame('ui', $variables->getByPath('metadata.displayNamespace'));
        self::assertSame('Hello', $variables->getByPath('metadata.fixture.selectedVariant.values.0.value'));
    }

    public function testUsesParentVariableForTransformerFixtureValues(): void
    {
        $metadata = $this->createMetadataWithTransformerValue();

        $snippet = new FluidUsageSnippetRenderer(new HtmlSourceHighlighter())->build($metadata);

        self::assertSame('<ui:Card bodytext="{bodytext}" />', $snippet);
        self::assertSame(
            '{ui:Card(bodytext: bodytext)}',
            new FluidUsageSnippetRenderer(new HtmlSourceHighlighter())->buildInline($metadata),
        );
    }

    /**
     * @param array<string, string> $slots
     */
    #[DataProvider('slotsDataProvider')]
    public function testBuildsUsageWithSlots(array $slots, string $tag, string $inline): void
    {
        $renderer = new FluidUsageSnippetRenderer(new HtmlSourceHighlighter());
        $metadata = $this->createMetadata($slots);

        self::assertSame($tag, $renderer->build($metadata, ComponentVariantValues::empty()));
        self::assertSame($inline, $renderer->buildInline($metadata, ComponentVariantValues::empty()));
        $highlighted = $renderer->render($metadata, ComponentVariantValues::empty());
        self::assertSame($tag, html_entity_decode(strip_tags($highlighted['tag']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        self::assertSame($inline, html_entity_decode(strip_tags($highlighted['inline']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * @return iterable<string, array{array<string, string>, string, string}>
     */
    public static function slotsDataProvider(): iterable
    {
        yield 'empty slots' => [['default' => '', 'footer' => ''], '<ui:Card />', '{ui:Card()}'];
        yield 'default slot' => [
            ['default' => '<strong>Content</strong>', 'footer' => ''],
            "<ui:Card>\n  <strong>Content</strong>\n</ui:Card>",
            "{f:format.raw(value: '<strong>Content</strong>') -> ui:Card()}",
        ];
        yield 'named slot' => [
            ['footer' => 'Footer'],
            "<ui:Card>\n  <f:fragment name=\"footer\">\n    Footer\n  </f:fragment>\n</ui:Card>",
            '',
        ];
        yield 'default and named slots' => [
            ['default' => 'Content', 'footer' => 'Footer'],
            "<ui:Card>\n  <f:fragment name=\"default\">\n    Content\n  </f:fragment>\n  <f:fragment name=\"footer\">\n    Footer\n  </f:fragment>\n</ui:Card>",
            '',
        ];
        yield 'zero is content' => [['default' => '0'], "<ui:Card>\n  0\n</ui:Card>", "{f:format.raw(value: '0') -> ui:Card()}"];
        yield 'whitespace is content' => [['default' => ' '], "<ui:Card>\n   \n</ui:Card>", "{f:format.raw(value: ' ') -> ui:Card()}"];
        yield 'multiline default slot' => [
            ['default' => "<div>\n  Content\n</div>"],
            "<ui:Card>\n  <div>\n    Content\n  </div>\n</ui:Card>",
            "{f:format.raw(value: '<div>\n  Content\n</div>') -> ui:Card(\n)}",
        ];
        yield 'quoted default slot' => [
            ['default' => "It's \\ready"],
            "<ui:Card>\n  It's \\ready\n</ui:Card>",
            "{f:format.raw(value: 'It\\'s \\\\ready') -> ui:Card()}",
        ];
    }

    public function testSlotOverridesReplaceStoredSlots(): void
    {
        $renderer = new FluidUsageSnippetRenderer(new HtmlSourceHighlighter());
        $metadata = $this->createMetadata(['default' => 'Stored']);
        $values = ComponentVariantValues::empty();

        self::assertSame(
            "<ui:Card>\n  <f:fragment name=\"footer\">\n    Override\n  </f:fragment>\n</ui:Card>",
            $renderer->build($metadata, $values, ['footer' => 'Override']),
        );
        self::assertSame('', $renderer->buildInline($metadata, $values, ['footer' => 'Override']));
        self::assertSame('<ui:Card />', $renderer->build($metadata, $values, []));
        self::assertSame('{ui:Card()}', $renderer->buildInline($metadata, $values, ['default' => '']));
    }

    /**
     * @param array<string, mixed> $values
     */
    #[DataProvider('valuesDataProvider')]
    public function testFormatsTypedOverrides(array $values, string $tag, string $inline): void
    {
        $renderer = new FluidUsageSnippetRenderer(new HtmlSourceHighlighter());
        $overrides = ComponentVariantValues::fromSubmittedValues($values);

        self::assertSame($tag, $renderer->build($this->createMetadata(), $overrides));
        self::assertSame($inline, $renderer->buildInline($this->createMetadata(), $overrides));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function valuesDataProvider(): iterable
    {
        yield 'booleans' => [['yes' => true, 'no' => false], '<ui:Card yes="{true}" no="{false}" />', '{ui:Card(yes: true, no: false)}'];
        yield 'numbers and null' => [['count' => 0, 'ratio' => 1.5, 'value' => null], '<ui:Card count="0" ratio="1.5" value="{null}" />', '{ui:Card(count: 0, ratio: 1.5, value: null)}'];
        yield 'compound placeholder' => [['data' => ['title' => 'Hello']], '<ui:Card data="{data}" />', '{ui:Card(data: data)}'];
        yield 'quotes and backslashes' => [
            ['title' => "It's \\ready"],
            '<ui:Card title="It\'s \\\\ready" />',
            "{ui:Card(title: 'It\\'s \\\\ready')}",
        ];
    }

    #[DataProvider('numericValuesDataProvider')]
    public function testQuotesNumericOverridesOnlyWhenFluidRequiresIt(int|float $value, string $inlineValue): void
    {
        $renderer = new FluidUsageSnippetRenderer(new HtmlSourceHighlighter());
        $metadata = $this->createMetadata();
        $values = ComponentVariantValues::fromSubmittedValues(['value' => $value]);
        $tag = '<ui:Card value="' . $value . '" />';
        $inline = '{ui:Card(value: ' . $inlineValue . ')}';

        self::assertSame($tag, $renderer->build($metadata, $values));
        self::assertSame($inline, $renderer->buildInline($metadata, $values));
        $highlighted = $renderer->render($metadata, $values);
        self::assertSame($tag, html_entity_decode(strip_tags($highlighted['tag']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        self::assertSame($inline, html_entity_decode(strip_tags($highlighted['inline']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * @return iterable<string, array{int|float, string}>
     */
    public static function numericValuesDataProvider(): iterable
    {
        yield 'zero integer' => [0, '0'];
        yield 'zero float' => [0.0, '0'];
        yield 'positive integer' => [12, '12'];
        yield 'integral float' => [12.0, '12'];
        yield 'positive decimal' => [1.5, '1.5'];
        yield 'negative integer' => [-1, "'-1'"];
        yield 'negative decimal' => [-1.5, "'-1.5'"];
        yield 'large positive float' => [1.0E+20, "'1.0E+20'"];
        yield 'large negative float' => [-1.0E+20, "'-1.0E+20'"];
        yield 'small positive float' => [1.0E-20, "'1.0E-20'"];
        yield 'small negative float' => [-1.0E-20, "'-1.0E-20'"];
        yield 'maximum integer' => [PHP_INT_MAX, (string)PHP_INT_MAX];
        yield 'minimum integer' => [PHP_INT_MIN, "'" . PHP_INT_MIN . "'"];
    }

    public function testChoosesNumericSyntaxForEachArgument(): void
    {
        $renderer = new FluidUsageSnippetRenderer(new HtmlSourceHighlighter());
        $metadata = $this->createMetadata();
        $values = ComponentVariantValues::fromSubmittedValues(['count' => 12, 'ratio' => -1.5, 'amount' => 1.0E+20]);

        self::assertSame('<ui:Card count="12" ratio="-1.5" amount="1.0E+20" />', $renderer->build($metadata, $values));
        self::assertSame("{ui:Card(count: 12, ratio: '-1.5', amount: '1.0E+20')}", $renderer->buildInline($metadata, $values));
    }

    #[DataProvider('wrappingDataProvider')]
    public function testWrapsOnlyWhenCallExceedsEightyCharacters(bool $inline, int $length, string $character): void
    {
        $renderer = new FluidUsageSnippetRenderer(new HtmlSourceHighlighter());
        $emptyCall = $inline ? "{ui:Card(title: '')}" : '<ui:Card title="" />';
        $value = str_repeat($character, $length - strlen($emptyCall));
        $values = ComponentVariantValues::fromSubmittedValues(['title' => $value]);
        $call = $inline ? $renderer->buildInline($this->createMetadata(), $values) : $renderer->build($this->createMetadata(), $values);
        $argument = $inline ? "title: '" . $value . "'" : 'title="' . $value . '"';
        $expected = $length <= 80
            ? ($inline ? '{ui:Card(' . $argument . ')}' : '<ui:Card ' . $argument . ' />')
            : ($inline ? "{ui:Card(\n  " . $argument . "\n)}" : "<ui:Card\n  " . $argument . "\n/>");

        self::assertSame($expected, $call);
    }

    /**
     * @return iterable<int, array{bool, int, string}>
     */
    public static function wrappingDataProvider(): iterable
    {
        foreach ([false, true] as $inline) {
            foreach ([80, 81] as $length) {
                foreach (['x', 'ä'] as $character) {
                    yield [$inline, $length, $character];
                }
            }
        }
    }

    public function testWrapsMultipleArgumentsAndOpeningDelimiterForSlots(): void
    {
        $renderer = new FluidUsageSnippetRenderer(new HtmlSourceHighlighter());
        $value = str_repeat('x', 60);
        $values = ComponentVariantValues::fromSubmittedValues(['title' => $value, 'enabled' => true]);
        $metadata = $this->createMetadata();

        self::assertSame(
            "<ui:Card\n  title=\"" . $value . "\"\n  enabled=\"{true}\"\n>\n  Content\n</ui:Card>",
            $renderer->build($metadata, $values, ['default' => 'Content']),
        );
        self::assertSame(
            "{ui:Card(\n  title: '" . $value . "',\n  enabled: true\n)}",
            $renderer->buildInline($metadata, $values),
        );
    }

    public function testMultilineArgumentsPutClosingDelimiterOnItsOwnLine(): void
    {
        $renderer = new FluidUsageSnippetRenderer(new HtmlSourceHighlighter());
        $values = ComponentVariantValues::fromSubmittedValues(['title' => "First\nSecond"]);

        self::assertSame("<ui:Card\n  title=\"First\nSecond\"\n/>", $renderer->build($this->createMetadata(), $values));
        self::assertSame("{ui:Card(\n  title: 'First\nSecond'\n)}", $renderer->buildInline($this->createMetadata(), $values));
    }

    public function testMissingMetadataReturnsEmptySources(): void
    {
        $renderer = new FluidUsageSnippetRenderer(new HtmlSourceHighlighter());

        self::assertSame('', $renderer->build(null));
        self::assertSame('', $renderer->buildInline(null));
        self::assertSame(['tag' => '', 'inline' => ''], $renderer->render(null));
    }

    /**
     * @param array<string, string> $slots
     */
    private function createMetadata(array $slots = []): ComponentMetadata
    {
        return new ComponentMetadata(
            'ui:Card:Default',
            'Default',
            'Default',
            'ui:Card',
            'Card',
            'ui',
            'Vendor\\Components',
            true,
            'Vendor\\Components',
            [],
            false,
            [],
            [],
            new ComponentTemplateMetadata('Card', null, null, null, [], null, null),
            new ComponentFixtureMetadata(
                null,
                null,
                null,
                [],
                null,
                new ComponentVariantMetadata('Default', [
                    new ComponentVariantValueMetadata('title', 'string', '', 'Hello', 'Hello', false, true, false),
                    new ComponentVariantValueMetadata('enabled', 'bool', '', 'true', true, false, true, false),
                ], $slots),
            ),
            [],
            [],
        );
    }

    private function createMetadataWithTransformerValue(): ComponentMetadata
    {
        return new ComponentMetadata(
            'ui:Card:Default',
            'Default',
            'Default',
            'ui:Card',
            'Card',
            'ui',
            'Vendor\\Components',
            true,
            'Vendor\\Components',
            [],
            false,
            [],
            [],
            new ComponentTemplateMetadata('Card', null, null, null, [], null, null),
            new ComponentFixtureMetadata(
                null,
                null,
                null,
                [],
                null,
                new ComponentVariantMetadata('Default', [
                    new ComponentVariantValueMetadata('content', 'string', '', 'Text', 'Text', false, true, false, 'bodytext', 'bodytext.content'),
                    new ComponentVariantValueMetadata('format', 'string', '', 'html', 'html', false, true, false, 'bodytext', 'bodytext.format'),
                ]),
            ),
            [],
            [],
        );
    }
}
