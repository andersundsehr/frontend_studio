<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Dto;

use Throwable;

final readonly class ComponentVariantValues
{
    /**
     * @param array<string, mixed> $values
     */
    private function __construct(
        private array $values,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public static function fromYamlValues(mixed $values): self
    {
        if (!is_array($values)) {
            return self::empty();
        }

        return new self(self::normalizeValueMap($values));
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $argumentTypes
     */
    public static function fromSubmittedValues(array $values, array $argumentTypes = []): self
    {
        return new self(self::normalizeValueMap($values))->normalizeForArgumentTypes($argumentTypes);
    }

    /**
     * @param array<string, string> $argumentTypes
     */
    public function normalizeForArgumentTypes(array $argumentTypes): self
    {
        $normalizedValues = [];
        foreach ($this->values as $name => $value) {
            $argumentType = $argumentTypes[$name] ?? null;
            if ($this->isBooleanArgumentType($argumentType)) {
                $normalizedValues[$name] = $this->normalizeSubmittedBooleanValue($value);
                continue;
            }

            if ($this->isCompoundArgumentType($argumentType)) {
                $normalizedValues[$name] = $this->normalizeSubmittedCompoundValue($value);
                continue;
            }

            $normalizedValues[$name] = self::normalizeYamlValue($value);
        }

        return new self($normalizedValues);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }

    /**
     * @return array<string, mixed>
     */
    public function toYamlArray(): array
    {
        return $this->values;
    }

    /**
     * @return list<array{name: string, type: string, value: string, nativeValue: mixed, isMultiline: bool, isFixtureValue: bool}>
     */
    public function toMetadataList(): array
    {
        $values = [];
        foreach ($this->values as $name => $value) {
            $values[] = [
                'name' => $name,
                'type' => get_debug_type($value),
                'value' => $this->formatDisplayValue($value),
                'nativeValue' => $value,
                'isMultiline' => is_string($value) && str_contains($value, "\n"),
                'isFixtureValue' => true,
            ];
        }

        return $values;
    }

    /**
     * @param array<string|int, mixed> $values
     * @return array<string, mixed>
     */
    private static function normalizeValueMap(array $values): array
    {
        $normalizedValues = [];
        foreach ($values as $name => $value) {
            $name = trim((string)$name);
            if ($name === '') {
                continue;
            }

            $normalizedValues[$name] = self::normalizeYamlValue($value);
        }

        return $normalizedValues;
    }

    private function isBooleanArgumentType(?string $type): bool
    {
        return $type !== null && preg_match('/\bbool(ean)?\b/i', $type) === 1;
    }

    private function isCompoundArgumentType(?string $type): bool
    {
        return $type !== null && preg_match('/\b(array|iterable|list|map|object|stdclass)\b/i', $type) === 1;
    }

    private function normalizeSubmittedBooleanValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool)$value;
    }

    private function normalizeSubmittedCompoundValue(mixed $value): mixed
    {
        if (is_string($value)) {
            try {
                $decodedValue = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decodedValue)) {
                    return self::normalizeYamlValue($decodedValue);
                }
            } catch (Throwable) {
            }
        }

        return self::normalizeYamlValue($value);
    }

    private static function normalizeYamlValue(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $nestedValue) {
                $value[$key] = self::normalizeYamlValue($nestedValue);
            }

            return $value;
        }

        if (is_object($value)) {
            return self::normalizeYamlValue(get_object_vars($value));
        }

        return $value;
    }

    private function formatDisplayValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        } catch (Throwable) {
            return get_debug_type($value);
        }
    }
}
