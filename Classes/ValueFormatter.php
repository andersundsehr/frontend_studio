<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio;

use Throwable;

final class ValueFormatter
{
    public static function format(mixed $value): string
    {
        if ($value === null) {
            return '';
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
