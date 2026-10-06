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

        if (self::normalizeWhitespace($first) === self::normalizeWhitespace($second)) {
            return $first;
        }

        $a = explode("\n", $first);
        $b = explode("\n", $second);
        if (count($a) !== count($b)) {
            throw new RuntimeException('Dynamic output inserted or removed lines; a baseline cannot be aligned safely.', 3378696614);
        }

        $normalizedA = array_map(self::normalizeWhitespace(...), $a);
        $normalizedB = array_map(self::normalizeWhitespace(...), $b);
        foreach ($a as $index => $line) {
            if ($normalizedA[$index] === $normalizedB[$index]) {
                continue;
            }

            // A moved line is not a changing value. Refuse ambiguous alignment.
            if (in_array($normalizedA[$index], $normalizedB, true) || in_array($normalizedB[$index], $normalizedA, true)) {
                throw new RuntimeException('Dynamic output moved lines; review the component before creating a baseline.', 8382529569);
            }

            $a[$index] = self::MARKER;
        }

        return implode("\n", $a);
    }

    public function matches(string $expected, string $actual): bool
    {
        if (str_contains($actual, self::MARKER)) {
            return false;
        }

        $parts = explode(self::MARKER, $expected);
        $patterns = array_map(
            static fn(string $part): string => str_replace(' ', '\\s+', preg_quote(self::normalizeWhitespace($part), '~')),
            $parts,
        );
        // A marker still masks only one formatted line. Whitespace may vary in length.
        return preg_match('~\\A' . implode('[^\\r\\n]*', $patterns) . '\\z~u', $actual) === 1;
    }

    public static function normalizeWhitespace(string $html): string
    {
        return preg_replace('/\\s+/u', ' ', $html) ?? $html;
    }
}
