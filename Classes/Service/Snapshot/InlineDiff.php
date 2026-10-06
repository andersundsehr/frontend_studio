<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service\Snapshot;

use cogpowered\FineDiff\Diff;
use cogpowered\FineDiff\Render\Renderer;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class InlineDiff extends Renderer
{
    public function render(string $expected, string $actual): string
    {
        $expectedLines = explode("\n", $expected);
        $actualLines = explode("\n", $actual);
        if (count($expectedLines) === count($actualLines)) {
            foreach ($expectedLines as $index => $line) {
                if ($line === Comparison::MARKER) {
                    $actualLines[$index] = Comparison::MARKER;
                }
            }

            $actual = implode("\n", $actualLines);
        }

        $formatter = new HtmlFormatter();
        $expected = $formatter->format(Comparison::normalizeWhitespace($expected));
        $actual = $formatter->format(Comparison::normalizeWhitespace($actual));
        $lines = explode("\n", new Diff(renderer: $this)->render($expected, $actual));
        $visible = [];
        foreach ($lines as $index => $line) {
            if (str_contains($line, '<fg=red>') || str_contains($line, '<fg=green>')) {
                for ($context = max(0, $index - 2); $context <= min(count($lines) - 1, $index + 2); $context++) {
                    $visible[$context] = true;
                }
            }
        }

        $output = ['<fg=red>[-removed-]</> <fg=green>{+added+}</>'];
        $previous = -1;
        foreach (array_keys($visible) as $index) {
            if ($index > $previous + 1) {
                $output[] = '<fg=gray>  …</>';
            }

            $output[] = '  ' . $lines[$index];
            $previous = $index;
        }

        return implode("\n", $output);
    }

    public function callback(string $opcode, string $from, int $from_offset, int $from_len): string
    {
        $text = mb_substr($from, $from_offset, $from_len);
        if ($opcode === 'c') {
            return OutputFormatter::escape($text);
        }

        $text = str_replace(["\n", "\r", "\t"], ['\\n', '\\r', '\\t'], $text);
        if (trim($text) === '') {
            $text = str_replace(' ', '␠', $text);
        }

        $text = OutputFormatter::escape($text);
        return $opcode === 'd' ? '<fg=red>[-' . $text . '-]</>' : '<fg=green>{+' . $text . '+}</>';
    }
}
