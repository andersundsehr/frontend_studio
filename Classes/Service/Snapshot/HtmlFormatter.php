<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service\Snapshot;

/**
 * @phpstan-type Element array{name: string, end: int}
 * @phpstan-type Whitespace array{tags: array<int, array{before?: string, attributes?: list<string>}>, end?: string}
 */
final readonly class HtmlFormatter
{
    public const string HEADER = "<!-- frontend-studio:snapshot-format:1 -->\n";

    private const string WHITESPACE = '~<!-- frontend-studio:snapshot-whitespace:([A-Za-z0-9+/=]+) -->\n\z~';

    private const string ATTRIBUTES = '~[^\\s=]+(?:\\s*=\\s*(?:"[^"]*"|\'[^\']*\'|[^\\s]+))?~';

    private const string INDENT = '  ';

    private const int LINE_LENGTH = 80;

    private const array VOID_ELEMENTS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    // Quoted angles, comments, CDATA and whitespace-sensitive bodies stay opaque.
    private const string TOKENS = '~<!--[\s\S]*?-->|<!\[CDATA\[[\s\S]*?\]\]>|<(script|style|pre|textarea)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>[\s\S]*?</\1\s*>|</?[a-zA-Z][^<>"\']*(?:(?:"[^"]*"|\'[^\']*\')[^<>"\']*)*>|<![^>]*>|[^<]+|<~i';

    private const string OPENING_TAG = '~^<([a-zA-Z][\w:-]*)(\s(?:[^>"\']|"[^"]*"|\'[^\']*\')*?)?(/?)>~';

    public function format(string $html): string
    {
        $html = $this->original($html);
        preg_match_all(self::TOKENS, $html, $matches);
        $tokens = $matches[0];
        $elements = $this->elements($tokens);
        // Do not repair malformed fragments or HTML with omitted closing tags.
        if ($elements === null) {
            return self::HEADER . $html . "\n";
        }

        $whitespace = ['tags' => []];
        $formatted = $this->formatTokens($tokens, $elements, $whitespace);
        $footer = $whitespace === ['tags' => []] ? ''
            : '<!-- frontend-studio:snapshot-whitespace:' . base64_encode(json_encode($whitespace, JSON_THROW_ON_ERROR)) . " -->\n";

        return self::HEADER . $formatted . $footer;
    }

    /** Restore the original HTML, including whitespace reused by the presentation. */
    public function original(string $html): string
    {
        $output = '';
        $offset = 0;
        foreach ($this->layoutRanges($html) as $range) {
            $output .= substr($html, $offset, $range['offset'] - $offset) . ($range['original'] ?? '');
            $offset = $range['offset'] + $range['length'];
        }

        return $output . substr($html, $offset);
    }

    /**
     * Reversible layout replacements keep original whitespace distinct from
     * presentation padding. The optional footer records reused whitespace by
     * markup position, so editing text or dynamic values does not shift it.
     * Diffs use the physical ranges to retain saved-file line numbers.
     *
     * @return list<array{offset: int, length: int, original?: string}>
     */
    public function layoutRanges(string $html): array
    {
        if (!str_starts_with($html, self::HEADER)) {
            return [];
        }

        $headerLength = strlen(self::HEADER);
        $bodyEnd = strlen($html);
        $whitespace = ['tags' => []];
        if (preg_match(self::WHITESPACE, $html, $footer, PREG_OFFSET_CAPTURE) === 1) {
            /** @var Whitespace $whitespace */
            $whitespace = json_decode((string)base64_decode($footer[1][0], true), true, 512, JSON_THROW_ON_ERROR);
            $bodyEnd = $footer[0][1];
        }

        $ranges = [['offset' => 0, 'length' => $headerLength]];
        $body = substr($html, $headerLength, $bodyEnd - $headerLength);
        preg_match_all(self::TOKENS, $body, $matches, PREG_OFFSET_CAPTURE);
        $tokens = array_column($matches[0], 0);
        $elements = $this->elements($tokens);
        $markupIndex = 0;
        if ($elements !== null) {
            foreach ($matches[0] as $index => [$token, $offset]) {
                $markup = isset($elements[$index]) || preg_match('~^</[\w:-]+\s*>$~', $token) === 1
                    || (str_starts_with($token, '<!') && !str_starts_with($token, '<![CDATA['));
                if (!$markup) {
                    continue;
                }

                $saved = $whitespace['tags'][$markupIndex++] ?? [];
                preg_match('/\n[\t ]*\z/', $tokens[$index - 1] ?? '', $padding);
                $length = strlen($padding[0] ?? '');
                if ($length > 0 || isset($saved['before'])) {
                    // Reused blank lines belong to this replacement as well.
                    if (isset($saved['before'])) {
                        preg_match('/[\t \r\n]*\z/', $tokens[$index - 1] ?? '', $padding);
                        $length = strlen($padding[0] ?? '');
                    }

                    $ranges[] = ['offset' => $headerLength + $offset - $length, 'length' => $length, 'original' => $saved['before'] ?? ''];
                }

                if (preg_match(self::OPENING_TAG, $token, $opening) === 1) {
                    if (isset($saved['attributes'])) {
                        $gaps = $this->attributeGaps($opening[2]);
                        $start = $headerLength + $offset + 1 + strlen($opening[1]);
                        foreach ($gaps as $gapIndex => $gap) {
                            $ranges[] = ['offset' => $start + $gap['offset'], 'length' => strlen($gap['value']), 'original' => $saved['attributes'][$gapIndex] ?? $gap['value']];
                        }
                    } elseif (preg_match('/\n[\t ]*(?=\/?>$)/', $opening[0], $padding, PREG_OFFSET_CAPTURE) === 1) {
                        $ranges[] = ['offset' => $headerLength + $offset + $padding[0][1], 'length' => strlen($padding[0][0])];
                    }
                }
            }
        }

        if (isset($whitespace['end'])) {
            preg_match('/[\t \r\n]*\z/', $body, $padding);
            $ranges[] = ['offset' => $bodyEnd - strlen($padding[0] ?? ''), 'length' => strlen($padding[0] ?? ''), 'original' => $whitespace['end']];
        } elseif (str_ends_with($body, "\n")) {
            $ranges[] = ['offset' => $bodyEnd - 1, 'length' => 1];
        }

        if ($bodyEnd < strlen($html)) {
            $ranges[] = ['offset' => $bodyEnd, 'length' => strlen($html) - $bodyEnd];
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
     * @param Whitespace $whitespace
     */
    private function formatTokens(array $tokens, array $elements, array &$whitespace): string
    {
        $depth = 0;
        $output = '';
        $markupIndex = 0;
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
            preg_match('/[\t \r\n]*\z/', $output, $padding);
            $original = $padding[0] ?? '';
            if (str_contains($original, "\n") || str_contains($original, "\r") || ($output !== '' && trim($output) === '')) {
                $whitespace['tags'][$markupIndex]['before'] = $original;
                $output = substr($output, 0, strlen($output) - strlen($original));
                $breaks = preg_match_all('/\r\n|\r|\n/', $original);
                $output .= str_repeat("\n", max($output !== '' ? 1 : 0, $breaks));
            } elseif ($output !== '') {
                $output .= "\n";
            }

            $formatted = $this->formatOpeningTag($token, $indent);
            if (preg_match(self::OPENING_TAG, $token, $opening) === 1 && preg_match(self::OPENING_TAG, $formatted, $newOpening) === 1 && $opening[2] !== $newOpening[2]) {
                $whitespace['tags'][$markupIndex]['attributes'] = array_column($this->attributeGaps($opening[2]), 'value');
            }

            $output .= $indent . $formatted;
            $markupIndex++;
            if (isset($elements[$index]) && $elements[$index]['end'] > $index) {
                $depth++;
            }
        }

        preg_match('/[\t \r\n]*\z/', $output, $padding);
        $original = $padding[0] ?? '';
        if (str_contains($original, "\n") || str_contains($original, "\r")) {
            $whitespace['end'] = $original;
            $output = substr($output, 0, strlen($output) - strlen($original)) . str_repeat("\n", max(1, preg_match_all('/\r\n|\r|\n/', $original)));
        } else {
            $output .= "\n";
        }

        return $output;
    }

    /** @return list<array{offset: int, value: string}> */
    private function attributeGaps(string $attributes): array
    {
        preg_match_all(self::ATTRIBUTES, $attributes, $matches, PREG_OFFSET_CAPTURE);
        $gaps = [];
        $offset = 0;
        foreach ($matches[0] as [$attribute, $start]) {
            $gaps[] = ['offset' => $offset, 'value' => substr($attributes, $offset, $start - $offset)];
            $offset = $start + strlen($attribute);
        }

        $gaps[] = ['offset' => $offset, 'value' => substr($attributes, $offset)];
        return $gaps;
    }

    private function formatOpeningTag(string $token, string $indent): string
    {
        if (!preg_match(self::OPENING_TAG, $token, $tag)) {
            return $token;
        }

        preg_match_all(self::ATTRIBUTES, trim($tag[2]), $attributes);
        $opening = '<' . $tag[1] . ($attributes[0] === [] ? '' : ' ' . implode(' ', $attributes[0])) . $tag[3] . '>';
        if (mb_strlen($indent . $opening) > self::LINE_LENGTH && $attributes[0] !== []) {
            $opening = '<' . $tag[1] . "\n" . $indent . self::INDENT . implode("\n" . $indent . self::INDENT, $attributes[0])
                . "\n" . $indent . $tag[3] . '>';
        }

        return $opening . substr($token, strlen($tag[0]));
    }
}
