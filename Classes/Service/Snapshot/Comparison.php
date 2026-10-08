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

        $matcher = new DynamicValueMatcher();
        $output = '';
        foreach ($a as $index => $token) {
            if ($token['type'] !== $b[$index]['type']) {
                throw new RuntimeException('Dynamic output changed HTML structure; review the component before updating the snapshot.', 8382529569);
            }

            if ($token['type'] === 'literal') {
                if (self::normalizeWhitespace($token['value']) !== self::normalizeWhitespace($b[$index]['value'])) {
                    throw new RuntimeException('Dynamic output changed HTML structure; review the component before updating the snapshot.', 8382529569);
                }

                $output .= $token['value'];
            } else {
                $output .= $matcher->create($token['value'], $b[$index]['value'], $token['type'] === 'attribute', $token['name']);
            }
        }

        return str_starts_with($first, HtmlFormatter::HEADER) ? new HtmlFormatter()->format($output) : $output;
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

        $matcher = new DynamicValueMatcher();
        foreach ($a as $index => $token) {
            if ($token['type'] !== $b[$index]['type']) {
                return false;
            }

            if ($token['type'] === 'literal') {
                if (self::normalizeWhitespace($token['value']) !== self::normalizeWhitespace($b[$index]['value'])) {
                    return false;
                }
            } elseif (!$matcher->matches($token['value'], $b[$index]['value'])) {
                return false;
            }
        }

        return true;
    }

    /** Mask display values only; compare the unmodified samples before calling this. */
    public function maskForDiff(string $expected, string $actual, ?string $secondSample = null): string
    {
        // Detect current dynamic values independently of the saved HTML structure.
        // If the samples change markup, keep that mismatch visible in the diff.
        if ($secondSample !== null && $this->canAlign($this->tokens($actual), $this->tokens($secondSample))) {
            try {
                $actual = $this->create($actual, $secondSample);
            } catch (RuntimeException $exception) {
                // DynamicValueMatcher cannot safely mask inserted or moved text lines.
                if ($exception->getCode() !== 1791270200) {
                    throw $exception;
                }
            }
        }

        $a = $this->tokens($expected);
        $b = $this->tokens($actual);
        if (!$this->canAlign($a, $b)) {
            return $actual;
        }

        $matcher = new DynamicValueMatcher();
        foreach ($a as $index => $token) {
            if ($token['type'] !== 'literal') {
                $b[$index]['value'] = $matcher->mask($token['value'], $b[$index]['value']);
            }
        }

        $output = implode('', array_column($b, 'value'));
        return str_starts_with($actual, HtmlFormatter::HEADER) ? new HtmlFormatter()->format($output) : $output;
    }

    /**
     * @param list<array{value: string, type: string, name: string}> $first
     * @param list<array{value: string, type: string, name: string}> $second
     */
    private function canAlign(array $first, array $second): bool
    {
        if (count($first) !== count($second)) {
            return false;
        }

        return array_all($first, fn(array $token, $index): bool => $token['type'] === $second[$index]['type'] && ($token['type'] !== 'literal' || self::normalizeWhitespace($token['value']) === self::normalizeWhitespace($second[$index]['value'])));
    }

    /** @return list<array{value: string, type: string, name: string}> */
    private function tokens(string $html): array
    {
        $html = new HtmlFormatter()->original($html);
        if (preg_match_all('~<!--[\s\S]*?-->|<![^>]*>|</?[a-zA-Z](?:[^>"\']|"[^"]*"|\'[^\']*\')*>|[^<]+|<~u', $html, $matches) === false) {
            throw new RuntimeException('Snapshot HTML is not valid UTF-8.', 1791270101);
        }

        $tokens = [];
        $previousWasMarkup = true;
        foreach ($matches[0] as $part) {
            $isMarkup = str_starts_with($part, '<');
            if ($isMarkup && $previousWasMarkup) {
                $tokens[] = ['value' => '', 'type' => 'text', 'name' => ''];
            }

            $previousWasMarkup = $isMarkup;
            if (str_starts_with($part, '<!')) {
                $tokens[] = ['value' => $part, 'type' => 'literal', 'name' => ''];
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
                        $tokens[] = ['value' => substr($part, $offset, $start - $offset), 'type' => 'literal', 'name' => ''];
                        $tokens[] = ['value' => $value, 'type' => 'attribute', 'name' => trim(explode('=', (string)$attribute[0][0], 2)[0])];
                        $offset = $start + strlen($value);
                        break;
                    }
                }

                $tokens[] = ['value' => substr($part, $offset), 'type' => 'literal', 'name' => ''];
            } else {
                $tokens[] = ['value' => $part, 'type' => 'text', 'name' => ''];
            }
        }

        if ($previousWasMarkup) {
            $tokens[] = ['value' => '', 'type' => 'text', 'name' => ''];
        }

        return $tokens;
    }

    public static function normalizeWhitespace(string $html): string
    {
        return preg_replace('/\s+/u', ' ', $html) ?? $html;
    }
}
