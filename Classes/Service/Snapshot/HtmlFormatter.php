<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service\Snapshot;

/** @phpstan-type Element array{name: string, end: int} */
final readonly class HtmlFormatter
{
    private const string INDENT = '  ';

    private const int LINE_LENGTH = 80;

    private const array VOID_ELEMENTS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    public function format(string $html): string
    {
        // Keep quoted angles, comments, CDATA and whitespace-sensitive bodies opaque.
        preg_match_all('~<!--[\s\S]*?-->|<!\[CDATA\[[\s\S]*?\]\]>|<(script|style|pre|textarea)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>[\s\S]*?</\1\s*>|</?[a-zA-Z][^<>"\']*(?:(?:"[^"]*"|\'[^\']*\')[^<>"\']*)*>|<![^>]*>|[^<]+|<~i', $html, $matches);
        $tokens = $matches[0];
        $elements = $this->elements($tokens);
        // Do not repair malformed fragments or HTML with omitted closing tags.
        $formatted = $elements === null ? $html : $this->formatTokens($tokens, $elements);

        return rtrim($formatted, "\r\n") . "\n";
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
                // Remove existing presentation indentation before the next tag only.
                // Retain text, entities, punctuation and literal spaces within values.
                $output .= preg_replace('/(?:\r?\n[\t ]*)+$/', '', $token) ?? $token;
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
