<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use Andersundsehr\FrontendStudio\Dto\ComponentVariantValues;

final readonly class FluidUsageSnippetRenderer
{
    public function __construct(
        private HtmlSourceHighlighter $htmlSourceHighlighter,
    ) {
    }

    /**
     * @param array<string, mixed>|null $selectedComponentMetadata
     */
    public function render(?array $selectedComponentMetadata, ?ComponentVariantValues $variantValueOverrides = null): string
    {
        $snippet = $this->build($selectedComponentMetadata, $variantValueOverrides);
        if ($snippet === '') {
            return '';
        }

        return $this->htmlSourceHighlighter->highlightFluidUsage($snippet);
    }

    /**
     * @param array<string, mixed>|null $selectedComponentMetadata
     */
    public function build(?array $selectedComponentMetadata, ?ComponentVariantValues $variantValueOverrides = null): string
    {
        if ($selectedComponentMetadata === null) {
            return '';
        }

        $displayNamespace = isset($selectedComponentMetadata['displayNamespace'])
            ? trim((string)$selectedComponentMetadata['displayNamespace'])
            : '';
        $componentName = isset($selectedComponentMetadata['componentName'])
            ? trim((string)$selectedComponentMetadata['componentName'])
            : '';
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
     * @param array<string, mixed> $selectedComponentMetadata
     * @return array<string, mixed>
     */
    private function collectFixtureValues(array $selectedComponentMetadata): array
    {
        $variantValues = $selectedComponentMetadata['fixture']['selectedVariant']['values'] ?? null;
        if (!is_array($variantValues)) {
            return [];
        }

        $values = [];
        foreach ($variantValues as $variantValue) {
            if (!is_array($variantValue) || ($variantValue['isFixtureValue'] ?? false) !== true) {
                continue;
            }

            $name = isset($variantValue['name']) ? trim((string)$variantValue['name']) : '';
            if ($name === '') {
                continue;
            }

            $values[$name] = array_key_exists('nativeValue', $variantValue)
                ? $variantValue['nativeValue']
                : (isset($variantValue['value']) ? (string)$variantValue['value'] : '');
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
