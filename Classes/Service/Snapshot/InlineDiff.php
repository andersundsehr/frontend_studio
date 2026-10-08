<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service\Snapshot;

use cogpowered\FineDiff\Diff;
use cogpowered\FineDiff\Granularity\Word;
use cogpowered\FineDiff\Render\Renderer;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class InlineDiff extends Renderer
{
    private const string REMOVED_PREFIX = '-';

    private const string ADDED_PREFIX = '+';

    /** @var list<int> */
    private array $expectedLines = [];

    /** @var list<int> */
    private array $actualLines = [];

    /** @var array<int, string> */
    private array $expectedIndents = [];

    /** @var array<int, string> */
    private array $actualIndents = [];

    private int $expectedOffset = 0;

    private int $actualOffset = 0;

    /** @var list<array{expected: int|null, actual: int|null, expectedText: string, actualText: string, expectedDiff: string, actualDiff: string, unchanged: bool, changed: bool}> */
    private array $rows = [];

    public function render(string $expected, string $actual): string
    {
        $actual = new Comparison()->maskForDiff($expected, $actual);
        [$expected, $this->expectedLines, $this->expectedIndents] = $this->source($expected);
        [$actual, $this->actualLines, $this->actualIndents] = $this->source($actual);
        $this->collect($expected, $actual);
        $visible = [];
        foreach ($this->rows as $index => $row) {
            if ($row['changed']) {
                for ($context = max(0, $index - 2); $context <= min(count($this->rows) - 1, $index + 2); $context++) {
                    $visible[$context] = true;
                }
            }
        }

        $width = strlen((string)max([...$this->expectedLines, ...$this->actualLines, 1]));
        $output = ['<fg=gray>  snapshot | actual</>'];
        $previous = -1;
        foreach (array_keys($visible) as $index) {
            if ($index > $previous + 1) {
                $output[] = '<fg=gray>  …</>';
            }

            $row = $this->rows[$index];
            if ($row['changed']) {
                if ($row['expected'] !== null) {
                    $text = $row['unchanged'] ? $this->mergeHighlights($row['expectedDiff']) : '<fg=red>' . $row['expectedText'] . '</>';
                    $output[] = '<fg=red>' . self::REMOVED_PREFIX . '</> <fg=gray>' . str_pad((string)$row['expected'], $width, ' ', STR_PAD_LEFT)
                        . ' | ' . str_pad('-', $width, ' ', STR_PAD_LEFT) . '</>  ' . $this->expectedIndents[$row['expected']] . $text;
                }

                if ($row['actual'] !== null) {
                    $text = $row['unchanged'] ? $this->mergeHighlights($row['actualDiff']) : '<fg=green>' . $row['actualText'] . '</>';
                    $output[] = '<fg=green>' . self::ADDED_PREFIX . '</> <fg=gray>' . str_pad('-', $width, ' ', STR_PAD_LEFT)
                        . ' | ' . str_pad((string)$row['actual'], $width, ' ', STR_PAD_LEFT) . '</>  ' . $this->actualIndents[$row['actual']] . $text;
                }

                $previous = $index;
                continue;
            }

            $numbers = str_pad((string)($row['expected'] ?? '-'), $width, ' ', STR_PAD_LEFT) . ' | '
                . str_pad((string)($row['actual'] ?? '-'), $width, ' ', STR_PAD_LEFT);
            $output[] = '  <fg=gray>' . $numbers . '</>  ' . ($this->expectedIndents[$row['expected'] ?? 0] ?? '') . $row['expectedText'];
            $previous = $index;
        }

        return implode("\n", $output);
    }

    public function callback(string $opcode, string $from, int $from_offset, int $from_len): string
    {
        $text = mb_substr($from, $from_offset, $from_len);
        foreach (mb_str_split($text) as $character) {
            $expected = $opcode === 'i' ? null : $this->expectedLines[$this->expectedOffset++];
            $actual = $opcode === 'd' ? null : $this->actualLines[$this->actualOffset++];
            $index = count($this->rows) - 1;
            if (
                $index < 0 || ($expected !== null && $this->rows[$index]['expected'] !== null && $this->rows[$index]['expected'] !== $expected)
                || ($actual !== null && $this->rows[$index]['actual'] !== null && $this->rows[$index]['actual'] !== $actual)
            ) {
                $this->rows[] = ['expected' => $expected, 'actual' => $actual, 'expectedText' => '', 'actualText' => '', 'expectedDiff' => '', 'actualDiff' => '', 'unchanged' => false, 'changed' => false];
                $index++;
            }

            $row = $this->rows[$index];
            $row['expected'] ??= $expected;
            $row['actual'] ??= $actual;
            $escaped = OutputFormatter::escape($character);
            if ($opcode !== 'i' && ($row['expectedText'] !== '' || trim($character) !== '')) {
                $row['expectedText'] .= $escaped;
                $row['expectedDiff'] .= $opcode === 'd' ? '<fg=red>' . $escaped . '</>' : $escaped;
            }

            if ($opcode !== 'd' && ($row['actualText'] !== '' || trim($character) !== '')) {
                $row['actualText'] .= $escaped;
                $row['actualDiff'] .= $opcode === 'i' ? '<fg=green>' . $escaped . '</>' : $escaped;
            }

            $row['unchanged'] = $row['unchanged'] || ($opcode === 'c' && trim($character) !== '');
            $row['changed'] = $row['changed'] || $opcode !== 'c';
            $this->rows[$index] = $row;
        }

        return '';
    }

    private function mergeHighlights(string $text): string
    {
        return str_replace([
            '</><fg=red>',
            '</><fg=green>',
        ], '', $text);
    }

    private function collect(string $expected, string $actual): void
    {
        $this->expectedOffset = 0;
        $this->actualOffset = 0;
        $this->rows = [];
        new Diff(granularity: new Word(), renderer: $this)->render($expected, $actual);
    }

    /** @return array{string, list<int>, array<int, string>} */
    private function source(string $html): array
    {
        // Keep presentation indentation separate from the normalized comparison text.
        $indents = [];
        foreach (explode("\n", $html) as $index => $sourceLine) {
            preg_match('/^[\t ]*/', $sourceLine, $indent);
            $indents[$index + 1] = $indent[0] ?? '';
        }

        $text = '';
        $lines = [];
        $line = 1;
        $offset = 0;
        $layout = new HtmlFormatter()->layoutRanges($html);
        $layout[] = ['offset' => strlen($html), 'length' => 0];
        foreach ($layout as $range) {
            preg_match_all('/\s+|[^\s]/u', substr($html, $offset, $range['offset'] - $offset), $matches);
            foreach ($matches[0] as $part) {
                $text .= Comparison::normalizeWhitespace($part);
                $lines[] = $line;
                $line += substr_count($part, "\n");
            }

            $line += substr_count(substr($html, $range['offset'], $range['length']), "\n");
            $offset = $range['offset'] + $range['length'];
        }

        return [$text, $lines, $indents];
    }
}
