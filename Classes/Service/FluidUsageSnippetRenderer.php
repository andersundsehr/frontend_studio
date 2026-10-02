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

    /**
     * @param array<string, string>|null $slotOverrides
     * @return array{tag: string, inline: string}
     */
    public function render(?ComponentMetadata $selectedComponentMetadata, ?ComponentVariantValues $variantValueOverrides = null, ?array $slotOverrides = null): array
    {
        $snippets = [
            'tag' => $this->build($selectedComponentMetadata, $variantValueOverrides, $slotOverrides),
            'inline' => $this->buildInline($selectedComponentMetadata, $variantValueOverrides, $slotOverrides),
        ];
        foreach ($snippets as $syntax => $snippet) {
            $snippets[$syntax] = $this->htmlSourceHighlighter->highlightFluidUsage($snippet);
        }

        return $snippets;
    }

    /**
     * @param array<string, string>|null $slotOverrides
     */
    public function build(?ComponentMetadata $selectedComponentMetadata, ?ComponentVariantValues $variantValueOverrides = null, ?array $slotOverrides = null): string
    {
        if ($selectedComponentMetadata === null) {
            return '';
        }

        $tagName = $this->getTagName($selectedComponentMetadata);
        if ($tagName === '') {
            return '';
        }

        $arguments = $this->formatArguments($selectedComponentMetadata, $variantValueOverrides);
        $slots = $this->collectSlots($selectedComponentMetadata, $slotOverrides);
        $openingTag = $this->formatCall('<' . $tagName . ($arguments === [] ? '' : ' '), $arguments, ' ', $slots === [] ? ' />' : '>');
        if ($slots === []) {
            return $openingTag;
        }

        if (array_keys($slots) === ['default']) {
            return $openingTag . "\n  " . str_replace("\n", "\n  ", $slots['default']) . "\n</" . $tagName . '>';
        }

        foreach ($slots as $name => $content) {
            $openingTag .= "\n  <f:fragment name=\"" . $this->escapeFluidAttributeValue($name) . "\">\n    "
                . str_replace("\n", "\n    ", $content) . "\n  </f:fragment>";
        }

        return $openingTag . "\n</" . $tagName . '>';
    }

    /**
     * @param array<string, string>|null $slotOverrides
     */
    public function buildInline(?ComponentMetadata $selectedComponentMetadata, ?ComponentVariantValues $variantValueOverrides = null, ?array $slotOverrides = null): string
    {
        if ($selectedComponentMetadata === null) {
            return '';
        }

        $tagName = $this->getTagName($selectedComponentMetadata);
        $slots = $this->collectSlots($selectedComponentMetadata, $slotOverrides);
        if ($tagName === '' || $slots !== [] && array_keys($slots) !== ['default']) {
            return '';
        }

        $opening = '{' . ($slots === [] ? '' : 'f:format.raw(value: ' . $this->quoteInlineString($slots['default']) . ') -> ') . $tagName . '(';

        return $this->formatCall($opening, $this->formatArguments($selectedComponentMetadata, $variantValueOverrides, true), ', ', ')}');
    }

    private function getTagName(ComponentMetadata $selectedComponentMetadata): string
    {
        $namespace = trim($selectedComponentMetadata->displayNamespace);
        $name = trim($selectedComponentMetadata->componentName);

        return $namespace === '' || $name === '' ? '' : $namespace . ':' . $name;
    }

    /**
     * @param array<string, string>|null $slotOverrides
     * @return array<string, string>
     */
    private function collectSlots(ComponentMetadata $selectedComponentMetadata, ?array $slotOverrides): array
    {
        return array_filter($slotOverrides ?? $selectedComponentMetadata->fixture->selectedVariant->slots ?? [], static fn(string $content): bool => $content !== '');
    }

    /**
     * @return list<string>
     */
    private function formatArguments(ComponentMetadata $selectedComponentMetadata, ?ComponentVariantValues $variantValueOverrides, bool $inline = false): array
    {
        $values = $variantValueOverrides?->toArray() ?? $this->collectFixtureValues($selectedComponentMetadata);
        $arguments = [];
        foreach ($values as $name => $value) {
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }

            $arguments[] = $inline
                ? $name . ': ' . $this->formatInlineValue($name, $value)
                : $name . '="' . $this->escapeFluidAttributeValue($this->formatFluidAttributeValue($name, $value)) . '"';
        }

        return $arguments;
    }

    /**
     * @param list<string> $arguments
     */
    private function formatCall(string $opening, array $arguments, string $separator, string $closing): string
    {
        $call = $opening . implode($separator, $arguments) . $closing;
        if (mb_strlen($call) <= 80 && !str_contains($call, "\n")) {
            return $call;
        }

        return rtrim($opening) . "\n"
            . ($arguments === [] ? '' : '  ' . implode(rtrim($separator) . "\n  ", $arguments) . "\n")
            . ltrim($closing);
    }

    private function formatInlineValue(string $name, mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/', (string)$value) === 1 ? (string)$value : $this->quoteInlineString((string)$value),
            is_string($value) => $this->quoteInlineString($value),
            default => $name,
        };
    }

    private function quoteInlineString(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
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
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
