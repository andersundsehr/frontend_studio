<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service\Snapshot;

/** @phpstan-type Element array{name: string, end: int, text: bool, children: bool} */
final readonly class HtmlFormatter
{
    private const string INDENT = '  ';

    private const int LINE_LENGTH = 80;

    private const array VOID_ELEMENTS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    private const array INLINE_ELEMENTS = ['a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'button', 'cite', 'code', 'data', 'del', 'dfn', 'em', 'i', 'img', 'input', 'ins', 'kbd', 'label', 'mark', 'meter', 'output', 'picture', 'progress', 'q', 'ruby', 's', 'samp', 'select', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr'];

    public function format(string $html): string
    {
        // Keep quoted angles, comments, CDATA and whitespace-sensitive bodies opaque.
        preg_match_all('~<!--[\s\S]*?-->|<!\[CDATA\[[\s\S]*?\]\]>|<(script|style|pre|textarea)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>[\s\S]*?</\1\s*>|</?[a-zA-Z][^<>"\']*(?:(?:"[^"]*"|\'[^\']*\')[^<>"\']*)*>|<![^>]*>|[^<]+|<~i', $html, $matches);
        $tokens = $matches[0];
        $elements = $this->elements($tokens);
        // Do not repair malformed fragments or HTML with omitted closing tags.
        $formatted = $elements === null ? $html : $this->formatRange($tokens, $elements, 0, count($tokens), 0, $elements[-1]['text']);

        return rtrim($formatted, "\r\n") . "\n";
    }

    /**
     * @param list<string> $tokens
     * @return array<int, Element>|null
     */
    private function elements(array $tokens): ?array
    {
        /** @var array<int, Element> $elements */
        $elements = [-1 => ['name' => '', 'end' => count($tokens), 'text' => false, 'children' => false]];
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
                $parentElement['children'] = true;
                // Even without surrounding text, adjacent inline elements must not gain spaces.
                $parentElement['text'] = $parentElement['text'] || in_array($name, self::INLINE_ELEMENTS, true);
                $elements[$parent] = $parentElement;
                $elements[$index] = ['name' => $name, 'end' => $index, 'text' => false, 'children' => false];
                if (
                    !in_array($name, self::VOID_ELEMENTS, true) && !str_ends_with($token, '/>')
                    && !preg_match('~^<(script|style|pre|textarea)\b[\s\S]*</\1\s*>$~i', $token)
                ) {
                    $stack[] = $index;
                }

                continue;
            }

            if (str_starts_with($token, '<![CDATA[')) {
                $parentElement['text'] = true;
            } elseif (str_starts_with($token, '<!')) {
                $parentElement['children'] = true;
            } elseif (trim($token) !== '') {
                $parentElement['text'] = true;
            }

            $elements[$parent] = $parentElement;
        }

        return count($stack) === 1 ? $elements : null;
    }

    /**
     * @param list<string> $tokens
     * @param array<int, Element> $elements
     */
    private function formatRange(array $tokens, array $elements, int $start, int $end, int $depth, bool $inline = false): string
    {
        $indent = str_repeat(self::INDENT, $depth);
        $parts = [];
        for ($index = $start; $index < $end; $index++) {
            $token = $tokens[$index];
            if (!$inline && trim($token) === '') {
                continue;
            }

            $part = ($inline ? '' : $indent) . $this->formatOpeningTag($token, $indent);
            if (isset($elements[$index]) && $elements[$index]['end'] > $index) {
                $element = $elements[$index];
                if ($inline || $element['text'] || !$element['children']) {
                    $part .= $this->formatRange($tokens, $elements, $index + 1, $element['end'] + 1, $depth + 1, true);
                } else {
                    $part .= "\n" . $this->formatRange($tokens, $elements, $index + 1, $element['end'], $depth + 1)
                        . "\n" . $indent . $tokens[$element['end']];
                }

                $index = $element['end'];
            }

            $parts[] = $part;
        }

        return implode($inline ? '' : "\n", $parts);
    }

    private function formatOpeningTag(string $token, string $indent): string
    {
        if (!preg_match('~^<([a-zA-Z][\w:-]*)(\s(?:[^>"\']|"[^"]*"|\'[^\']*\')*?)(/?)>~', $token, $tag)) {
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
