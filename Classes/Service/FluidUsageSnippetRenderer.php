<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use Andersundsehr\FrontendStudio\Dto\ComponentMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantValues;

final readonly class FluidUsageSnippetRenderer
{
    public function __construct(
        private HtmlSourceHighlighter $htmlSourceHighlighter,
    ) {
    }

    public function render(?ComponentMetadata $selectedComponentMetadata, ?ComponentVariantValues $variantValueOverrides = null): string
    {
        $snippet = $this->build($selectedComponentMetadata, $variantValueOverrides);
        if ($snippet === '') {
            return '';
        }

        return $this->htmlSourceHighlighter->highlightFluidUsage($snippet);
    }

    public function build(?ComponentMetadata $selectedComponentMetadata, ?ComponentVariantValues $variantValueOverrides = null): string
    {
        if ($selectedComponentMetadata === null) {
            return '';
        }

        $displayNamespace = trim($selectedComponentMetadata->displayNamespace);
        $componentName = trim($selectedComponentMetadata->componentName);
        if ($displayNamespace === '' || $componentName === '') {
            return '';
        }

        $values = $variantValueOverrides?->toArray() ?? $this->collectFixtureValues($selectedComponentMetadata);
        $attributes = '';
        foreach ($values as $name => $value) {
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }

            $attributes .= ' ' . $name . '="' . $this->escapeFluidAttributeValue($this->formatFluidAttributeValue($name, $value)) . '"';
        }

        return '<' . $displayNamespace . ':' . $componentName . $attributes . ' />';
    }

    /**
     * @return array<string, mixed>
     */
    private function collectFixtureValues(ComponentMetadata $selectedComponentMetadata): array
    {
        $variantValues = $selectedComponentMetadata->fixture?->selectedVariant?->values;
        if ($variantValues === null) {
            return [];
        }

        $values = [];
        foreach ($variantValues as $variantValue) {
            if (!$variantValue->isFixtureValue) {
                continue;
            }

            $name = trim($variantValue->parentName ?? $variantValue->name);
            if ($name === '') {
                continue;
            }

            $values[$name] = $variantValue->parentName === null ? $variantValue->nativeValue : [];
        }

        return $values;
    }

    private function formatFluidAttributeValue(string $name, mixed $value): string
    {
        if ($value === null) {
            return '{null}';
        }

        if (is_bool($value)) {
            return $value ? '{true}' : '{false}';
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        if (is_string($value)) {
            return $value;
        }

        return '{' . $name . '}';
    }

    private function escapeFluidAttributeValue(string $value): string
    {
        return str_replace(
            ['&', '"', '<', '>'],
            ['&amp;', '&quot;', '&lt;', '&gt;'],
            $value,
        );
    }
}
