<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

final class HtmlSourceHighlighter
{
    // Possessive text chunks avoid backtracking exhaustion on unfinished nested expressions.
    private const string FLUID_EXPRESSION_PATTERN = <<<'REGEX'
        (?<fluid>\{(?:[^{}'"]++|"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|(?&fluid))*\})
        REGEX;

    public function highlight(string $html): string
    {
        return $this->highlightSource($html);
    }

    public function highlightFluidTemplate(string $template): string
    {
        return $this->highlightSource($template, true);
    }

    /**
     * @param list<array{line: ?int, character?: ?int, severity: string, message: string}> $diagnostics
     */
    public function highlightFluidDiagnostics(string $template, array $diagnostics): string
    {
        $byLine = [];
        $characters = [];
        $summary = '';
        foreach ($diagnostics as $diagnostic) {
            $severity = $diagnostic['severity'] === 'deprecation' ? 'deprecation' : 'error';
            $message = '<span class="frontend-studio-template-diagnostic is-' . $severity . '">'
                . htmlspecialchars(ucfirst($severity) . ': ' . $diagnostic['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
            if ($diagnostic['line'] === null) {
                $summary .= $message;
            } else {
                $byLine[$diagnostic['line']][] = $message;
                if (($diagnostic['character'] ?? null) !== null) {
                    $characters[$diagnostic['line']][] = $diagnostic['character'];
                }
            }
        }

        $lines = explode("\n", $this->highlightSource($template, true, false));
        $source = '';
        foreach ($lines as $index => $line) {
            $number = $index + 1;
            $messages = $byLine[$number] ?? [];
            $line = $this->markCharacters($line, $characters[$number] ?? []);
            $source .= '<span class="frontend-studio-template-line' . ($messages !== [] ? ' is-error' : '') . '" data-line="' . $number . '">'
                . '<span class="frontend-studio-template-line-number" aria-hidden="true">' . $number . '</span>'
                . '<code>' . $line . '</code>' . implode('', $messages) . '</span>';
            if ($number < count($lines)) {
                $source .= "\n";
            }
        }

        return ($summary !== '' ? '<div class="frontend-studio-template-summary">' . $summary . '</div>' : '')
            . '<pre class="frontend-studio-variant-html-source frontend-studio-template-source">' . $source . '</pre>';
    }

    public function highlightFluidUsage(string $fluidUsage): string
    {
        return $this->highlightSource($fluidUsage, true, false);
    }

    /**
     * @param list<int> $characters Fluid's one-based UTF-8 byte positions.
     */
    private function markCharacters(string $line, array $characters): string
    {
        if ($characters === []) {
            return $line;
        }

        $plainLine = rtrim(html_entity_decode(strip_tags($line), ENT_QUOTES | ENT_HTML5, 'UTF-8'), "\r");
        $characters = array_values(array_unique(array_filter($characters, static fn(int $character): bool => $character > 0
            && $character <= strlen($plainLine) + 1 && preg_match('//u', substr($plainLine, 0, $character - 1)) === 1)));
        sort($characters);
        if ($characters === []) {
            return $line;
        }

        // Only split escaped text, never markup or an HTML entity. Retain token spans.
        $parts = preg_split('/(<[^>]+>)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $offset = 0;
        $marked = '';
        foreach ($parts as $part) {
            if (str_starts_with($part, '<')) {
                $marked .= $part;
                continue;
            }

            $text = html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $end = $offset + strlen($text);
            $start = 0;
            while ($characters !== [] && $characters[0] - 1 <= $end) {
                $character = array_shift($characters);
                $position = $character - 1 - $offset;
                $marked .= htmlspecialchars(substr($text, $start, $position - $start), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '<span class="frontend-studio-template-marker" data-character="' . $character . '" aria-hidden="true"></span>';
                $start = $position;
            }

            $marked .= htmlspecialchars(substr($text, $start), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $offset = $end;
        }

        return $marked;
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
        // Tokenize the entire source first, then close spans at line boundaries.
        // This preserves multiline token classes and makes line annotation safe.
        $parts = preg_split('/(\r?\n)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        return implode('', array_map(static fn(string $part): string => in_array($part, ["\n", "\r\n"], true)
            ? $part
            : '<span class="frontend-studio-variant-html-source__' . $token . '">'
                . htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>', $parts));
    }
}
