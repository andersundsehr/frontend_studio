<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service\Snapshot;

use RuntimeException;

final readonly class Comparison
{
    public const string MARKER = '<!-- frontend-studio:dynamic-line -->';

    public function create(string $first, string $second): string
    {
        if (str_contains($first, self::MARKER) || str_contains($second, self::MARKER)) {
            throw new RuntimeException('Rendered output contains the reserved dynamic-line marker.', 6425975682);
        }

        $a = explode("\n", $first);
        $b = explode("\n", $second);
        if (count($a) !== count($b)) {
            throw new RuntimeException('Dynamic output inserted or removed lines; a baseline cannot be aligned safely.', 3378696614);
        }

        foreach ($a as $index => $line) {
            if ($line === $b[$index]) {
                continue;
            }

            // A moved line is not a changing value. Refuse ambiguous alignment.
            if (in_array($line, $b, true) || in_array($b[$index], $a, true)) {
                throw new RuntimeException('Dynamic output moved lines; review the component before creating a baseline.', 8382529569);
            }

            $a[$index] = self::MARKER;
        }

        return implode("\n", $a);
    }

    public function matches(string $expected, string $actual): bool
    {
        $a = explode("\n", $expected);
        $b = explode("\n", $actual);
        if (count($a) !== count($b) || str_contains($actual, self::MARKER)) {
            return false;
        }

        return array_all($a, fn($line, $index): bool => $line === self::MARKER || $line === $b[$index]);
    }
}
