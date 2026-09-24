<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service;

use Andersundsehr\FrontendStudio\Dto\ComponentFixtureMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantValueMetadata;
use Andersundsehr\FrontendStudio\Service\FluidUsageSnippetRenderer;
use Andersundsehr\FrontendStudio\Service\HtmlSourceHighlighter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use TYPO3Fluid\Fluid\Core\Variables\StandardVariableProvider;

#[CoversClass(ComponentMetadata::class)]
#[CoversClass(ComponentFixtureMetadata::class)]
#[CoversClass(ComponentVariantMetadata::class)]
#[CoversClass(ComponentVariantValueMetadata::class)]
#[CoversClass(FluidUsageSnippetRenderer::class)]
final class FluidUsageSnippetRendererTest extends TestCase
{
    public function testBuildsFluidUsageFromTypedFixtureValues(): void
    {
        $metadata = $this->createMetadata();

        $snippet = new FluidUsageSnippetRenderer(new HtmlSourceHighlighter())->build($metadata);

        self::assertSame('<ui:Card title="Hello" enabled="{true}" />', $snippet);
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
    }

    private function createMetadata(): ComponentMetadata
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
            null,
            new ComponentFixtureMetadata(
                null,
                null,
                null,
                [],
                null,
                new ComponentVariantMetadata('Default', [
                    new ComponentVariantValueMetadata('title', 'string', '', 'Hello', 'Hello', false, true, false),
                    new ComponentVariantValueMetadata('enabled', 'bool', '', 'true', true, false, true, false),
                ]),
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
            null,
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
