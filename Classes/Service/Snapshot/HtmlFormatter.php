<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service\Snapshot;

/** @phpstan-type Element array{name: string, end: int} */
final readonly class HtmlFormatter
{
    public const string HEADER = "<!-- frontend-studio:snapshot-format:1 -->\n";

    private const string INDENT = '  ';

    private const int LINE_LENGTH = 80;

    private const array VOID_ELEMENTS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    // Quoted angles, comments, CDATA and whitespace-sensitive bodies stay opaque.
    private const string TOKENS = '~<!--[\s\S]*?-->|<!\[CDATA\[[\s\S]*?\]\]>|<(script|style|pre|textarea)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>[\s\S]*?</\1\s*>|</?[a-zA-Z][^<>"\']*(?:(?:"[^"]*"|\'[^\']*\')[^<>"\']*)*>|<![^>]*>|[^<]+|<~i';

    private const string OPENING_TAG = '~^<([a-zA-Z][\w:-]*)(\s(?:[^>"\']|"[^"]*"|\'[^\']*\')*?)(/?)>~';

    public function format(string $html): string
    {
        $html = $this->original($html);
        preg_match_all(self::TOKENS, $html, $matches);
        $tokens = $matches[0];
        $elements = $this->elements($tokens);
        // Do not repair malformed fragments or HTML with omitted closing tags.
        $formatted = $elements === null ? $html : $this->formatTokens($tokens, $elements);

        return self::HEADER . $formatted . "\n";
    }

    /** Remove only layout added by this formatter, retaining original text whitespace. */
    public function original(string $html): string
    {
        $output = '';
        $offset = 0;
        foreach ($this->layoutRanges($html) as $range) {
            $output .= substr($html, $offset, $range['offset'] - $offset);
            $offset = $range['offset'] + $range['length'];
        }

        return $output . substr($html, $offset);
    }

    /**
     * Source ranges let comparisons ignore presentation whitespace while diffs
     * still refer to the line numbers in the saved snapshot file.
     * The header distinguishes formatted snapshots from literal template newlines.
     *
     * @return list<array{offset: int, length: int}>
     */
    public function layoutRanges(string $html): array
    {
        if (!str_starts_with($html, self::HEADER)) {
            return [];
        }

        $headerLength = strlen(self::HEADER);
        $ranges = [['offset' => 0, 'length' => $headerLength]];
        preg_match_all(self::TOKENS, substr($html, $headerLength), $matches, PREG_OFFSET_CAPTURE);
        $tokens = array_column($matches[0], 0);
        $elements = $this->elements($tokens);
        if ($elements !== null) {
            foreach ($matches[0] as $index => [$token, $offset]) {
                $markup = isset($elements[$index]) || preg_match('~^</[\w:-]+\s*>$~', $token) === 1
                    || (str_starts_with($token, '<!') && !str_starts_with($token, '<![CDATA['));
                if ($markup && preg_match('/\n[\t ]*\z/', $tokens[$index - 1] ?? '', $padding) === 1) {
                    $ranges[] = ['offset' => $headerLength + $offset - strlen($padding[0]), 'length' => strlen($padding[0])];
                }

                if (preg_match(self::OPENING_TAG, $token, $opening) === 1 && preg_match('/\n[\t ]*(?=\/?>$)/', $opening[0], $padding, PREG_OFFSET_CAPTURE) === 1) {
                    $ranges[] = ['offset' => $headerLength + $offset + $padding[0][1], 'length' => strlen($padding[0][0])];
                }
            }
        }

        if (strlen($html) > $headerLength && str_ends_with($html, "\n")) {
            $ranges[] = ['offset' => strlen($html) - 1, 'length' => 1];
        }

        return $ranges;
    }

    /**
     * @param list<string> $tokens
     * @return array<int, Element>|null
     */
    private function elements(array $tokens): ?array
    {
        /** @var array<int, Element> $elements */
        $elements = [-1 => ['name' => '', 'end' => count($tokens)]];
        $stack = [-1];
        foreach ($tokens as $index => $token) {
            $parent = $stack[count($stack) - 1] ?? -1;
            $parentElement = $elements[$parent];
            if (preg_match('~^</([\w:-]+)\s*>$~', $token, $closing)) {
                if ($parent === -1 || $parentElement['name'] !== strtolower($closing[1])) {
                    return null;
                }

                $parentElement['end'] = $index;
                $elements[$parent] = $parentElement;
                array_pop($stack);
                continue;
            }

            if (preg_match('~^<([a-zA-Z][\w:-]*)\b~', $token, $opening)) {
                $name = strtolower($opening[1]);
                $elements[$index] = ['name' => $name, 'end' => $index];
                if (
                    !in_array($name, self::VOID_ELEMENTS, true) && !str_ends_with($token, '/>')
                    && !preg_match('~^<(script|style|pre|textarea)\b[\s\S]*</\1\s*>$~i', $token)
                ) {
                    $stack[] = $index;
                }

                continue;
            }
        }

        return count($stack) === 1 ? $elements : null;
    }

    /**
     * @param list<string> $tokens
     * @param array<int, Element> $elements
     */
    private function formatTokens(array $tokens, array $elements): string
    {
        $depth = 0;
        $output = '';
        foreach ($tokens as $index => $token) {
            $closing = preg_match('~^</[\w:-]+\s*>$~', $token) === 1;
            $markup = isset($elements[$index]) || $closing || (str_starts_with($token, '<!') && !str_starts_with($token, '<![CDATA['));
            if (!$markup) {
                $output .= $token;
                continue;
            }

            if ($closing) {
                $depth--;
            }

            $indent = str_repeat(self::INDENT, $depth);
            if ($output !== '') {
                $output .= "\n";
            }

            $output .= $indent . $this->formatOpeningTag($token, $indent);
            if (isset($elements[$index]) && $elements[$index]['end'] > $index) {
                $depth++;
            }
        }

        return $output;
    }

    private function formatOpeningTag(string $token, string $indent): string
    {
        if (!preg_match(self::OPENING_TAG, $token, $tag)) {
            return $token;
        }

        preg_match_all('~[^\s=]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s]+))?~', trim($tag[2]), $attributes);
        $opening = '<' . $tag[1] . ($attributes[0] === [] ? '' : ' ' . implode(' ', $attributes[0])) . $tag[3] . '>';
        if (mb_strlen($indent . $opening) > self::LINE_LENGTH && $attributes[0] !== []) {
            $opening = '<' . $tag[1] . "\n" . $indent . self::INDENT . implode("\n" . $indent . self::INDENT, $attributes[0])
                . "\n" . $indent . $tag[3] . '>';
        }

        return $opening . substr($token, strlen($tag[0]));
    }
}
