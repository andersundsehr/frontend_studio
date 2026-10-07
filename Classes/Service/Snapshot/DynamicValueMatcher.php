<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service\Snapshot;

use cogpowered\FineDiff\Diff;
use RuntimeException;

final readonly class DynamicValueMatcher
{
    public function create(string $first, string $second, bool $attribute, string $name = ''): string
    {
        if (Comparison::normalizeWhitespace($first) === Comparison::normalizeWhitespace($second)) {
            return $first;
        }

        $a = $this->patterns($first, $name);
        $b = $this->patterns($second, $name);
        if (array_column($a, 'kind') !== array_column($b, 'kind')) {
            return $attribute ? Comparison::MARKER : $this->text($first, $second);
        }

        $output = '';
        $offsetA = 0;
        $offsetB = 0;
        foreach ($a as $index => $value) {
            $literalA = substr($first, $offsetA, $value['start'] - $offsetA);
            $literalB = substr($second, $offsetB, $b[$index]['start'] - $offsetB);
            if ($attribute && Comparison::normalizeWhitespace($literalA) !== Comparison::normalizeWhitespace($literalB)) {
                return Comparison::MARKER;
            }

            $output .= $this->text($literalA, $literalB);
            $sampleA = substr($first, $value['start'], $value['end'] - $value['start']);
            $sampleB = substr($second, $b[$index]['start'], $b[$index]['end'] - $b[$index]['start']);
            $output .= Comparison::normalizeWhitespace($sampleA) === Comparison::normalizeWhitespace($sampleB) ? $sampleA : Comparison::MARKER;
            $offsetA = $value['end'];
            $offsetB = $b[$index]['end'];
        }

        $tailA = substr($first, $offsetA);
        $tailB = substr($second, $offsetB);
        if ($attribute && Comparison::normalizeWhitespace($tailA) !== Comparison::normalizeWhitespace($tailB)) {
            return Comparison::MARKER;
        }

        return $output . $this->text($tailA, $tailB);
    }

    public function matches(string $expected, string $actual): bool
    {
        return preg_match($this->expression($expected), $actual) === 1;
    }

    public function mask(string $expected, string $actual): string
    {
        if (preg_match($this->expression($expected), $actual, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return $actual;
        }

        for ($index = count($matches) - 1; $index > 0; $index--) {
            [$value, $offset] = $matches[$index];
            // Keep original source line numbers when a region spans multiple lines.
            if (str_contains($value, "\n")) {
                return $actual;
            }

            $actual = substr_replace($actual, Comparison::MARKER, $offset, strlen($value));
        }

        return $actual;
    }

    private function expression(string $expected): string
    {
        $parts = explode(Comparison::MARKER, $expected);
        $expression = '~\A';
        foreach ($parts as $index => $part) {
            if ($index > 0) {
                $expression .= preg_match('~(?:[?&]|&amp;)[\w.-]+=$~u', $parts[$index - 1]) === 1 ? '([^&#\s]*?)' : '(.*?)';
            }

            $expression .= str_replace(' ', '\\s+', preg_quote(Comparison::normalizeWhitespace($part), '~'));
        }

        return $expression . '\z~su';
    }

    /** @return list<array{kind: string, start: int, end: int}> */
    private function patterns(string $value, string $name): array
    {
        $month = '(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)';
        $weekday = '(?:Mon(?:day)?|Tue(?:sday)?|Wed(?:nesday)?|Thu(?:rsday)?|Fri(?:day)?|Sat(?:urday)?|Sun(?:day)?)';
        $time = '(?:[01]?\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:[.,]\d+)?)?(?:\s*[AP]M)?(?:\s*(?:Z|[+-]\d{2}:?\d{2}))?';
        $date = '(?:\d{4}[-/.]\d{1,2}[-/.]\d{1,2}|\d{1,2}[-/.]\d{1,2}[-/.]\d{4}|\d{4}[- /]' . $month . '[- /](?:\d{1,2}|' . $weekday . ')|\d{1,2}\s+' . $month . '\s+\d{4}|' . $month . '\s+\d{1,2}(?:st|nd|rd|th)?,?\s+\d{4})';
        $patterns = [
            'date' => ['~(?<!\d)' . $date . '(?:[T\s]+' . $time . ')?(?!\d)~iu', 0],
            'time' => ['~(?<!\d)' . $time . '(?!\d)~iu', 0],
            'uuid' => ['~[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}|(?<![a-f0-9])[a-f0-9]{32}(?![a-f0-9])~iu', 0],
            'ulid' => ['~(?<![0-9A-HJKMNP-TV-Z])[0-7][0-9A-HJKMNP-TV-Z]{25}(?![0-9A-HJKMNP-TV-Z])~iu', 0],
            'url' => ['~(?:[?&]|&amp;)([\w.-]+)=([^&#\s"\'<>]*)~u', 2],
            'unix' => ['~(?<!\d)\d{10}(?:\d{3})?(?!\d)~u', 0],
            'hex' => ['~(?<![a-f0-9])[a-f0-9]{16,}(?![a-f0-9])~iu', 0],
            'number' => ['~\d+~u', 0],
        ];
        // A URL parameter is one value, even if it contains a date, UUID or number.
        $patterns = ['url' => $patterns['url']] + $patterns;

        $ranges = [];
        foreach ($patterns as $kind => [$pattern, $group]) {
            if ($kind === 'unix' && preg_match('/time|timestamp/i', $name) !== 1) {
                continue;
            }

            preg_match_all($pattern, $value, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                if (!isset($match[$group])) {
                    continue;
                }

                [$text, $start] = $match[$group];
                $end = $start + strlen($text);
                if (array_any($ranges, static fn(array $range): bool => $start < $range['end'] && $end > $range['start'])) {
                    continue;
                }

                $ranges[] = ['kind' => $kind === 'url' ? 'url:' . ($match[1][0] ?? '') : $kind, 'start' => $start, 'end' => $end];
            }
        }

        usort($ranges, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);
        return $ranges;
    }

    private function text(string $first, string $second): string
    {
        if (Comparison::normalizeWhitespace($first) === Comparison::normalizeWhitespace($second)) {
            return $first;
        }

        [$a, $offsetsA] = $this->source($first);
        [$b, $offsetsB] = $this->source($second);
        $positionA = 0;
        $positionB = 0;
        $startA = null;
        $startB = 0;
        $ranges = [];
        foreach ([...new Diff()->getOpcodes($a, $b)->getOpcodes(), 'c0'] as $opcode) {
            $type = $opcode[0];
            $length = preg_match('/^[cdi](\d+)/', $opcode, $match) === 1 ? (int)$match[1] : 1;
            if ($type === 'c') {
                if ($startA !== null) {
                    $changedA = substr($first, $offsetsA[$startA], $offsetsA[$positionA] - $offsetsA[$startA]);
                    $changedB = substr($second, $offsetsB[$startB], $offsetsB[$positionB] - $offsetsB[$startB]);
                    if (str_contains($changedA, "\n") || str_contains($changedB, "\n")) {
                        throw new RuntimeException('Dynamic text inserted, removed or moved lines; review the component before updating the snapshot.', 1791270200);
                    }

                    $ranges[] = ['start' => $offsetsA[$startA], 'end' => $offsetsA[$positionA], 'insertedWord' => preg_match('/^[\p{L}\p{N}\p{M}_-]+$/u', $changedB) === 1];
                    $startA = null;
                }

                $positionA += $length;
                $positionB += $length;
            } else {
                if ($startA === null) {
                    $startA = $positionA;
                    $startB = $positionB;
                }

                if ($type === 'd') {
                    $positionA += $length;
                } else {
                    $positionB += $length;
                }
            }
        }

        preg_match_all('/[\p{L}\p{N}\p{M}_-]+/u', $first, $words, PREG_OFFSET_CAPTURE);
        foreach ($ranges as &$range) {
            foreach ($words[0] as [$word, $start]) {
                $end = $start + strlen($word);
                $overlap = $range['start'] < $end && $range['end'] > $start;
                $insertion = $range['start'] === $range['end'] && $range['start'] >= $start && $range['start'] <= $end && $range['insertedWord'];
                if ($overlap || $insertion) {
                    $range['start'] = min($range['start'], $start);
                    $range['end'] = max($range['end'], $end);
                }
            }
        }

        unset($range);

        $output = '';
        $offset = 0;
        foreach ($ranges as $range) {
            if ($range['start'] < $offset) {
                // Several character changes inside one word share a single marker.
                $offset = max($offset, $range['end']);
                continue;
            }

            $output .= substr($first, $offset, $range['start'] - $offset) . Comparison::MARKER;
            $offset = $range['end'];
        }

        $output .= substr($first, $offset);

        return $output;
    }

    /** @return array{string, list<int>} */
    private function source(string $value): array
    {
        preg_match_all('/\s+|[^\s]/u', $value, $matches, PREG_OFFSET_CAPTURE);
        $text = '';
        $offsets = [];
        foreach ($matches[0] as [$part, $offset]) {
            $text .= Comparison::normalizeWhitespace($part);
            $offsets[] = $offset;
        }

        $offsets[] = strlen($value);
        return [$text, $offsets];
    }
}
