<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service\Snapshot;

use cogpowered\FineDiff\Diff;
use cogpowered\FineDiff\Granularity\Word;
use cogpowered\FineDiff\Render\Renderer;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class InlineDiff extends Renderer
{
    private const string REMOVED_OPEN = '(-';

    private const string REMOVED_CLOSE = '-)';

    private const string ADDED_OPEN = '(+';

    private const string ADDED_CLOSE = '+)';

    /** @var list<int> */
    private array $expectedLines = [];

    /** @var list<int> */
    private array $actualLines = [];

    private int $expectedOffset = 0;

    private int $actualOffset = 0;

    /** @var list<array{expected: int|null, actual: int|null, text: string, expectedText: string, actualText: string, unchanged: bool, changed: bool}> */
    private array $rows = [];

    public function render(string $expected, string $actual): string
    {
        $actual = new Comparison()->maskForDiff($expected, $actual);
        [$expected, $this->expectedLines] = $this->source($expected);
        [$actual, $this->actualLines] = $this->source($actual);
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
        $output = ['<fg=red>' . self::REMOVED_OPEN . 'removed' . self::REMOVED_CLOSE . '</> <fg=green>'
            . self::ADDED_OPEN . 'added' . self::ADDED_CLOSE . '</>  <fg=gray>snapshot | actual</>'];
        $previous = -1;
        foreach (array_keys($visible) as $index) {
            if ($index > $previous + 1) {
                $output[] = '<fg=gray>  …</>';
            }

            $row = $this->rows[$index];
            if ($row['changed'] && !$row['unchanged']) {
                if ($row['expected'] !== null) {
                    $output[] = '<fg=red>-</> <fg=gray>' . str_pad((string)$row['expected'], $width, ' ', STR_PAD_LEFT)
                        . ' | ' . str_pad('-', $width, ' ', STR_PAD_LEFT) . '</>  <fg=red>' . $row['expectedText'] . '</>';
                }

                if ($row['actual'] !== null) {
                    $output[] = '<fg=green>+</> <fg=gray>' . str_pad('-', $width, ' ', STR_PAD_LEFT)
                        . ' | ' . str_pad((string)$row['actual'], $width, ' ', STR_PAD_LEFT) . '</>  <fg=green>' . $row['actualText'] . '</>';
                }

                $previous = $index;
                continue;
            }

            $numbers = str_pad((string)($row['expected'] ?? '-'), $width, ' ', STR_PAD_LEFT) . ' | '
                . str_pad((string)($row['actual'] ?? '-'), $width, ' ', STR_PAD_LEFT);
            $text = str_replace([
                self::REMOVED_CLOSE . '</><fg=red>' . self::REMOVED_OPEN,
                self::ADDED_CLOSE . '</><fg=green>' . self::ADDED_OPEN,
            ], '', $row['text']);
            $output[] = '<fg=gray>' . $numbers . '</>  ' . $text;
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
                $this->rows[] = ['expected' => $expected, 'actual' => $actual, 'text' => '', 'expectedText' => '', 'actualText' => '', 'unchanged' => false, 'changed' => false];
                $index++;
            }

            $row = $this->rows[$index];
            $row['expected'] ??= $expected;
            $row['actual'] ??= $actual;
            $escaped = OutputFormatter::escape($character);
            if ($opcode !== 'i') {
                $row['expectedText'] .= $escaped;
            }

            if ($opcode !== 'd') {
                $row['actualText'] .= $escaped;
            }

            $row['unchanged'] = $row['unchanged'] || ($opcode === 'c' && trim($character) !== '');
            $row['text'] .= match ($opcode) {
                'd' => '<fg=red>' . self::REMOVED_OPEN . $escaped . self::REMOVED_CLOSE . '</>',
                'i' => '<fg=green>' . self::ADDED_OPEN . $escaped . self::ADDED_CLOSE . '</>',
                default => $escaped,
            };
            $row['changed'] = $row['changed'] || $opcode !== 'c';
            $this->rows[$index] = $row;
        }

        return '';
    }

    private function collect(string $expected, string $actual): void
    {
        $this->expectedOffset = 0;
        $this->actualOffset = 0;
        $this->rows = [];
        new Diff(granularity: new Word(), renderer: $this)->render($expected, $actual);
    }

    /** @return array{string, list<int>} */
    private function source(string $html): array
    {
        preg_match_all('/\s+|[^\s]/u', $html, $matches);
        $text = '';
        $lines = [];
        $line = 1;
        foreach ($matches[0] as $part) {
            $text .= Comparison::normalizeWhitespace($part);
            $lines[] = $line;
            $line += substr_count($part, "\n");
        }

        return [$text, $lines];
    }
}
