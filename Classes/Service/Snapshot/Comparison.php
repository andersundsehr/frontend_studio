<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service\Snapshot;

use RuntimeException;

final readonly class Comparison
{
    public const string MARKER = '{{frontend-studio:dynamic}}';

    private const string LEGACY_MARKER = '<!-- frontend-studio:dynamic-line -->';

    public function create(string $first, string $second): string
    {
        if (str_contains($first, self::MARKER) || str_contains($second, self::MARKER) || str_contains($first, self::LEGACY_MARKER) || str_contains($second, self::LEGACY_MARKER)) {
            throw new RuntimeException('Rendered output contains a reserved dynamic marker.', 6425975682);
        }

        $a = $this->tokens($first);
        $b = $this->tokens($second);
        if (count($a) !== count($b)) {
            throw new RuntimeException('Dynamic output inserted or removed tokens; a snapshot cannot be aligned safely.', 3378696614);
        }

        $valuesA = array_column($a, 'value');
        $valuesB = array_column($b, 'value');
        $output = '';
        foreach ($a as $index => $token) {
            if (self::normalizeWhitespace($token['value']) === self::normalizeWhitespace($b[$index]['value'])) {
                $output .= $token['value'];
                continue;
            }

            if (!$token['dynamic'] || !$b[$index]['dynamic']) {
                throw new RuntimeException('Dynamic output changed HTML structure; review the component before updating the snapshot.', 8382529569);
            }

            if (in_array($token['value'], $valuesB, true) || in_array($b[$index]['value'], $valuesA, true)) {
                throw new RuntimeException('Dynamic output moved values; a snapshot cannot be aligned safely.', 8382529570);
            }

            $output .= self::MARKER;
        }

        return $output;
    }

    public function matches(string $expected, string $actual): bool
    {
        if (str_contains($expected, self::LEGACY_MARKER)) {
            throw new RuntimeException('Whole-line dynamic markers are no longer supported. Regenerate this snapshot with --update.', 1791270100);
        }

        if (str_contains($actual, self::MARKER) || str_contains($actual, self::LEGACY_MARKER)) {
            return false;
        }

        $a = $this->tokens($expected);
        $b = $this->tokens($actual);
        if (count($a) !== count($b)) {
            return false;
        }

        foreach ($a as $index => $token) {
            if ($token['dynamic'] && $token['value'] === self::MARKER && $b[$index]['dynamic']) {
                continue;
            }

            if ($token['dynamic'] !== $b[$index]['dynamic'] || self::normalizeWhitespace($token['value']) !== self::normalizeWhitespace($b[$index]['value'])) {
                return false;
            }
        }

        return true;
    }

    public function maskForDiff(string $expected, string $actual): string
    {
        $a = $this->tokens($expected);
        $b = $this->tokens($actual);
        if (count($a) !== count($b)) {
            return $actual;
        }

        foreach ($a as $index => $token) {
            if ($token['dynamic'] !== $b[$index]['dynamic'] || (!$token['dynamic'] && self::normalizeWhitespace($token['value']) !== self::normalizeWhitespace($b[$index]['value']))) {
                return $actual;
            }
        }

        foreach ($a as $index => $token) {
            if ($token['dynamic'] && $token['value'] === self::MARKER) {
                $b[$index]['value'] = self::MARKER;
            }
        }

        return implode('', array_column($b, 'value'));
    }

    /** @return list<array{value: string, dynamic: bool}> */
    private function tokens(string $html): array
    {
        if (preg_match_all('~<!--[\s\S]*?-->|<![^>]*>|</?[a-zA-Z](?:[^>"\']|"[^"]*"|\'[^\']*\')*>|[^<]+|<~u', $html, $matches) === false) {
            throw new RuntimeException('Snapshot HTML is not valid UTF-8.', 1791270101);
        }

        $tokens = [];
        foreach ($matches[0] as $part) {
            if (str_starts_with($part, '<!')) {
                $tokens[] = ['value' => $part, 'dynamic' => false];
                continue;
            }

            if (str_starts_with($part, '<')) {
                preg_match_all('~\s+[\w:-]+\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>/]+))~u', $part, $attributes, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
                $offset = 0;
                foreach ($attributes as $attribute) {
                    foreach ([1, 2, 3] as $group) {
                        if ($attribute[$group][0] === null) {
                            continue;
                        }

                        [$value, $start] = $attribute[$group];
                        $tokens[] = ['value' => substr($part, $offset, $start - $offset), 'dynamic' => false];
                        array_push($tokens, ...$this->valueTokens($value));
                        $offset = $start + strlen($value);
                        break;
                    }
                }

                $tokens[] = ['value' => substr($part, $offset), 'dynamic' => false];
            } else {
                array_push($tokens, ...$this->valueTokens($part));
            }
        }

        return $tokens;
    }

    /** @return list<array{value: string, dynamic: bool}> */
    private function valueTokens(string $value): array
    {
        preg_match_all('~[^\s<>"\']+|[\s<>"\']+~u', $value, $matches);
        return array_map(static fn(string $part): array => ['value' => $part, 'dynamic' => preg_match('~^[^\s<>"\']+$~u', $part) === 1], $matches[0]);
    }

    public static function normalizeWhitespace(string $html): string
    {
        return preg_replace('/\s+/u', ' ', $html) ?? $html;
    }
}
