<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Transformer;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

use function date_default_timezone_get;
use function enum_exists;
use function filter_var;
use function in_array;
use function is_numeric;
use function is_scalar;
use function is_string;
use function preg_replace;

use const FILTER_VALIDATE_BOOL;

/**
 * Converts a fixture value to the type of a transformer input.
 * Already converted values are returned unchanged, so normalizing twice is safe.
 */
final class InputNormalizer
{
    public static function normalize(string $type, mixed $value): mixed
    {
        if ($value === null || $type === 'mixed') {
            return $value;
        }

        if (enum_exists($type)) {
            if ($value instanceof $type) {
                return $value;
            }

            foreach ($type::cases() as $case) {
                if ($case->name === $value) {
                    return $case;
                }
            }

            throw new InvalidArgumentException('Invalid enum case "' . (is_scalar($value) ? $value : get_debug_type($value)) . '" for "' . $type . '".', 1768901558);
        }

        if (in_array($type, [DateTime::class, DateTimeImmutable::class, DateTimeInterface::class], true)) {
            if ($value instanceof $type) {
                return $value;
            }

            if (!is_string($value)) {
                throw new InvalidArgumentException('Date transformer input must be an ISO-8601 string.', 1768901559);
            }

            $value = preg_replace('/(?:Z|[+-]\d{2}:?\d{2})$/', '', $value) ?: $value;
            return $type === DateTime::class ? new DateTime($value, new DateTimeZone(date_default_timezone_get())) : new DateTimeImmutable($value, new DateTimeZone(date_default_timezone_get()));
        }

        return match ($type) {
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOL),
            'int', 'integer' => is_numeric($value) ? (int)$value : $value,
            'float', 'double' => is_numeric($value) ? (float)$value : $value,
            'string' => is_scalar($value) ? (string)$value : $value,
            default => $value,
        };
    }
}
