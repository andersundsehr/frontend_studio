<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

final class HtmlSourceHighlighter
{
    public function highlight(string $html): string
    {
        $source = '';
        $offset = 0;
        $length = strlen($html);

        while ($offset < $length) {
            $nextTagOffset = strpos($html, '<', $offset);
            if ($nextTagOffset === false) {
                $source .= $this->wrapText(substr($html, $offset));
                break;
            }

            if ($nextTagOffset > $offset) {
                $source .= $this->wrapText(substr($html, $offset, $nextTagOffset - $offset));
            }

            if (str_starts_with(substr($html, $nextTagOffset), '<!--')) {
                $commentEndOffset = strpos($html, '-->', $nextTagOffset + 4);
                $commentLength = $commentEndOffset === false ? $length - $nextTagOffset : $commentEndOffset + 3 - $nextTagOffset;
                $source .= $this->wrap('comment', substr($html, $nextTagOffset, $commentLength));
                $offset = $nextTagOffset + $commentLength;
                continue;
            }

            $tagEndOffset = $this->findTagEndOffset($html, $nextTagOffset);
            if ($tagEndOffset === null) {
                $source .= $this->wrapText(substr($html, $nextTagOffset));
                break;
            }

            $tag = substr($html, $nextTagOffset, $tagEndOffset - $nextTagOffset + 1);
            $source .= $this->highlightTag($tag);
            $offset = $tagEndOffset + 1;
        }

        return '<pre class="frontend-studio-variant-html-source"><code>' . $source . '</code></pre>';
    }

    private function findTagEndOffset(string $html, int $tagStartOffset): ?int
    {
        $quote = null;
        $length = strlen($html);

        for ($offset = $tagStartOffset + 1; $offset < $length; $offset++) {
            $character = $html[$offset];
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
                continue;
            }

            if ($character === '>') {
                return $offset;
            }
        }

        return null;
    }

    private function highlightTag(string $tag): string
    {
        if (preg_match('/^<![^>]*>$/s', $tag) === 1) {
            return $this->wrap('doctype', $tag);
        }

        if (preg_match('/^(<\\/?)([^\\s>\\/]+)(.*?)(\\/?>)$/s', $tag, $matches) !== 1) {
            return $this->wrapText($tag);
        }

        return $this->wrap('punctuation', $matches[1])
            . $this->wrap('tag', $matches[2])
            . $this->highlightAttributes($matches[3])
            . $this->wrap('punctuation', $matches[4]);
    }

    private function highlightAttributes(string $attributes): string
    {
        $highlightedAttributes = '';
        $offset = 0;

        preg_match_all('/([^\\s"\\\'<>\\/=]+)(\\s*=\\s*)?("([^"]*)"|\\\'([^\\\']*)\\\'|[^\\s"\\\'=<>`]+)?/s', $attributes, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $attributeOffset = $match[0][1];
            if ($attributeOffset > $offset) {
                $highlightedAttributes .= $this->wrapText(substr($attributes, $offset, $attributeOffset - $offset));
            }

            $highlightedAttributes .= $this->wrap('attribute', $match[1][0]);

            if (($match[2][0] ?? '') !== '') {
                $highlightedAttributes .= $this->wrap('punctuation', $match[2][0]);
            }

            if (($match[3][0] ?? '') !== '') {
                $highlightedAttributes .= $this->wrap('string', $match[3][0]);
            }

            $offset = $attributeOffset + strlen($match[0][0]);
        }

        if ($offset < strlen($attributes)) {
            $highlightedAttributes .= $this->wrapText(substr($attributes, $offset));
        }

        return $highlightedAttributes;
    }

    private function wrapText(string $text): string
    {
        return $text === '' ? '' : $this->wrap('text', $text);
    }

    private function wrap(string $token, string $value): string
    {
        return '<span class="frontend-studio-variant-html-source__' . $token . '">'
            . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</span>';
    }
}
