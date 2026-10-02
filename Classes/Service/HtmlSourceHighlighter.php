<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

final class HtmlSourceHighlighter
{
    private const string FLUID_EXPRESSION_PATTERN = <<<'REGEX'
        (?<fluid>\{(?:[^{}'"]+|"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|(?&fluid))*\})
        REGEX;

    public function highlight(string $html): string
    {
        return $this->highlightSource($html);
    }

    public function highlightFluidTemplate(string $template): string
    {
        return $this->highlightSource($template, true);
    }

    public function highlightFluidUsage(string $fluidUsage): string
    {
        return $this->highlightSource($fluidUsage, true, false);
    }

    private function highlightSource(string $html, bool $highlightFluidExpressions = false, bool $wrapSource = true): string
    {
        $source = '';
        $offset = 0;
        $length = strlen($html);

        while ($offset < $length) {
            $nextTagOffset = strpos($html, '<', $offset);
            if (
                $highlightFluidExpressions
                && preg_match('/' . self::FLUID_EXPRESSION_PATTERN . '/s', $html, $expression, PREG_OFFSET_CAPTURE, $offset) === 1
                && ($nextTagOffset === false || $expression[0][1] < $nextTagOffset)
            ) {
                $source .= $this->wrapText(substr($html, $offset, $expression[0][1] - $offset));
                $source .= $this->highlightFluidExpression($expression[0][0]);
                $offset = $expression[0][1] + strlen($expression[0][0]);
                continue;
            }

            if ($nextTagOffset === false) {
                $source .= $this->wrapText(substr($html, $offset), $highlightFluidExpressions);
                break;
            }

            if ($nextTagOffset > $offset) {
                $source .= $this->wrapText(substr($html, $offset, $nextTagOffset - $offset), $highlightFluidExpressions);
            }

            if (str_starts_with(substr($html, $nextTagOffset), '<!--')) {
                $commentEndOffset = strpos($html, '-->', $nextTagOffset + 4);
                $commentLength = $commentEndOffset === false ? $length - $nextTagOffset : $commentEndOffset + 3 - $nextTagOffset;
                $source .= $this->wrap('comment', substr($html, $nextTagOffset, $commentLength));
                $offset = $nextTagOffset + $commentLength;
                continue;
            }

            $tagEndOffset = $this->findTagEndOffset($html, $nextTagOffset, $highlightFluidExpressions);
            if ($tagEndOffset === null) {
                $source .= $this->wrapText(substr($html, $nextTagOffset), $highlightFluidExpressions);
                break;
            }

            $tag = substr($html, $nextTagOffset, $tagEndOffset - $nextTagOffset + 1);
            $source .= $this->highlightTag($tag, $highlightFluidExpressions);
            $offset = $tagEndOffset + 1;
        }

        return $wrapSource ? '<pre class="frontend-studio-variant-html-source"><code>' . $source . '</code></pre>' : $source;
    }

    private function findTagEndOffset(string $html, int $tagStartOffset, bool $fluid = false): ?int
    {
        $quote = null;
        $length = strlen($html);

        for ($offset = $tagStartOffset + 1; $offset < $length; $offset++) {
            $character = $html[$offset];
            if ($quote !== null) {
                if ($fluid && $character === '\\') {
                    $offset++;
                    continue;
                }

                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($fluid && $character === '{' && preg_match('/\G' . self::FLUID_EXPRESSION_PATTERN . '/s', $html, $expression, 0, $offset) === 1) {
                $offset += strlen($expression[0]) - 1;
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

    private function highlightTag(string $tag, bool $highlightFluidExpressions = false): string
    {
        if (preg_match('/^<![^>]*>$/s', $tag) === 1) {
            return $this->wrap('doctype', $tag);
        }

        if (preg_match('/^(<\\/?)([^\\s>\\/]+)(.*?)(\\/?>)$/s', $tag, $matches) !== 1) {
            return $this->wrapText($tag);
        }

        return $this->wrap('punctuation', $matches[1])
            . $this->wrap('tag', $matches[2])
            . $this->highlightAttributes($matches[3], $highlightFluidExpressions)
            . $this->wrap('punctuation', $matches[4]);
    }

    private function highlightAttributes(string $attributes, bool $highlightFluidExpressions = false): string
    {
        $highlightedAttributes = '';
        $offset = 0;

        $pattern = '(?<attribute>[^\\s"\\\'<>\\/=]+)(?<equals>\\s*=\\s*)?(?<value>"(?:\\\\.|[^"\\\\])*"|\\\'(?:\\\\.|[^\\\'\\\\])*\\\'|[^\\s"\\\'=<>`]+)?';
        preg_match_all('/' . ($highlightFluidExpressions ? self::FLUID_EXPRESSION_PATTERN . '|' : '') . $pattern . '/s', $attributes, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $attributeOffset = $match[0][1];
            if ($attributeOffset > $offset) {
                $highlightedAttributes .= $this->wrapText(substr($attributes, $offset, $attributeOffset - $offset));
            }

            if (($match['fluid'][0] ?? '') !== '') {
                $highlightedAttributes .= $this->highlightFluidExpression($match['fluid'][0]);
                $offset = $attributeOffset + strlen($match[0][0]);
                continue;
            }

            $highlightedAttributes .= $this->wrap('attribute', $match['attribute'][0]);

            if (($match['equals'][0] ?? '') !== '') {
                $highlightedAttributes .= $this->wrap('punctuation', $match['equals'][0]);
            }

            if (($match['value'][0] ?? '') !== '') {
                $highlightedAttributes .= $highlightFluidExpressions
                    ? $this->highlightFluidExpressions($match['value'][0], 'string')
                    : $this->wrap('string', $match['value'][0]);
            }

            $offset = $attributeOffset + strlen($match[0][0]);
        }

        if ($offset < strlen($attributes)) {
            $highlightedAttributes .= $this->wrapText(substr($attributes, $offset));
        }

        return $highlightedAttributes;
    }

    private function wrapText(string $text, bool $highlightFluidExpressions = false): string
    {
        if ($text === '') {
            return '';
        }

        if (!$highlightFluidExpressions) {
            return $this->wrap('text', $text);
        }

        return $this->highlightFluidExpressions($text);
    }

    private function highlightFluidExpressions(string $text, string $plainToken = 'text'): string
    {
        $source = '';
        $offset = 0;

        preg_match_all('/' . self::FLUID_EXPRESSION_PATTERN . '/s', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match) {
            $expressionOffset = $match[0][1];
            if ($expressionOffset > $offset) {
                $source .= $this->wrap($plainToken, substr($text, $offset, $expressionOffset - $offset));
            }

            $source .= $this->highlightFluidExpression($match[0][0]);
            $offset = $expressionOffset + strlen($match[0][0]);
        }

        if ($offset < strlen($text)) {
            $source .= $this->wrap($plainToken, substr($text, $offset));
        }

        return $source;
    }

    private function highlightFluidExpression(string $expression): string
    {
        preg_match_all('~(?<string>"(?:\\\\.|[^"\\\\])*"|\\\'(?:\\\\.|[^\\\'\\\\])*\\\')|(?<tag>[\\w.]+:[\\w.]+(?=\\s*\\())|(?<attribute>[\\w-]+(?=\\s*[:=]))|-?\\d+(?:\\.\\d+)?|[\\w.]+(?:-[\\w.]+)*|(?<punctuation>->|[{}(),:=|+*/%<>!?&^\\[\\]\\-])|(?<text>\\s+)|.~su', $expression, $matches, PREG_SET_ORDER);
        $source = '';

        foreach ($matches as $match) {
            $token = match (true) {
                ($match['string'] ?? '') !== '' => 'string',
                ($match['tag'] ?? '') !== '' => 'tag',
                ($match['attribute'] ?? '') !== '' => 'attribute',
                ($match['punctuation'] ?? '') !== '' => 'punctuation',
                ($match['text'] ?? '') !== '' => 'text',
                default => 'fluid-expression',
            };
            $source .= $this->wrap($token, $match[0]);
        }

        return $source;
    }

    private function wrap(string $token, string $value): string
    {
        return '<span class="frontend-studio-variant-html-source__' . $token . '">'
            . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</span>';
    }
}
