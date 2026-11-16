<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Transformer;

use function array_key_exists;
use function explode;
use function is_array;

/**
 * Transformer inputs of nested value objects use dotted names such as "link.url".
 * Their fixture values are stored as nested maps, so lookups follow the path.
 */
final class InputPath
{
    /**
     * @param array<array-key, mixed> $values
     */
    public static function has(array $values, string $path): bool
    {
        $current = $values;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false;
            }

            $current = $current[$segment];
        }

        return true;
    }

    /**
     * @param array<array-key, mixed> $values
     */
    public static function get(array $values, string $path): mixed
    {
        $current = $values;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @param array<array-key, mixed> $values
     */
    public static function set(array &$values, string $path, mixed $value): void
    {
        $target = &$values;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($target)) {
                $target = [];
            }

            $target = &$target[$segment];
        }

        $target = $value;
    }
}
