<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service\Snapshot;

use DateTimeZone;

final readonly class DatePatterns
{
    /** @return array<string, string> */
    public static function expressions(): array
    {
        static $expressions = null;
        if ($expressions !== null) {
            return $expressions;
        }

        $year = '\d{4}';
        $shortYear = '\d{2}';
        $month = '(?:0?[1-9]|1[0-2])';
        $day = '(?:0?[1-9]|[12]\d|3[01])';
        $ordinal = $day . '(?:st|nd|rd|th)?';
        $hour = '(?:[01]?\d|2[0-3])';
        $minute = '[0-5]\d';
        $fraction = '(?:[.,_]\d{1,6})?';
        $namedMonth = '(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)';
        $weekday = '(?:Mon(?:day)?|Tue(?:sday)?|Wed(?:nesday)?|Thu(?:rsday)?|Fri(?:day)?|Sat(?:urday)?|Sun(?:day)?)';
        $offset = '[+-](?:0\d|1[0-4]):?[0-5]\d';
        $zoneDefinition = '(?(DEFINE)(?<zoneName>' . implode('|', array_map(static fn(string $zone): string => preg_quote($zone, '~'), DateTimeZone::listIdentifiers())) . '))';
        $zoneName = '(?&zoneName)';
        $zone = '(?:UTC|GMT|CEST|CET|EEST|EET|BST|[ECMP][DS]T|JST|[AN][EWCS]*[DS]T|' . $zoneName . '|' . $offset . '|Z)';
        $zoneSuffix = '(?:\s*' . $zone . '){0,2}';
        $colonTime = $hour . ':' . $minute . '(?::' . $minute . $fraction . ')?';
        $separatedTime = $hour . '[-/]' . $minute . '(?:[-/]' . $minute . $fraction . ')?';
        $compactTime = $hour . $minute . '(?:' . $minute . $fraction . ')?';
        $time = '(?:' . $colonTime . '|' . $separatedTime . '|' . $compactTime . '|' . $hour . ')(?:\s*[AP]M)?' . $zoneSuffix;
        $separator = '[-/.:_]';
        $numericDate = '(?:' . $year . $separator . $month . $separator . $day . '|' . $day . '[-/.]' . $month . '[-/.](?:' . $year . '|' . $shortYear . ')|' . $month . '[-/.]' . $day . '[-/.](?:' . $year . '|' . $shortYear . '))';
        $compactDate = '(?:' . $year . '|' . $shortYear . ')(?:0[1-9]|1[0-2])(?:0[1-9]|[12]\d|3[01])';
        $namedDate = '(?:' . $year . '[- /]' . $namedMonth . '[- /](?:' . $day . '|' . $weekday . ')|' . $ordinal . '[- ]' . $namedMonth . '(?:[- ]' . $year . ')?|' . $namedMonth . '\s+' . $ordinal . '(?:,?\s+' . $year . ')?)';
        $join = '(?:[T,\s/@_-]+|\\\\(?:' . $zone . ')?)';
        $namedDateTime = '(?:' . $weekday . ',?\s*)?(?:' . $namedMonth . '\s+' . $day . '\s+' . $time . '\s+' . $year . '|' . $namedDate . '(?:' . $join . $time . ')?)';
        $dateTime = '(?:' . $numericDate . '|' . $compactDate . ')(?:' . $join . '?' . $time . ')?';
        $reversed = $hour . '/' . $minute . '/' . $minute . '/' . $day . '/' . $month . '/' . $year;
        $spaced = $year . '\s+' . $month . '\s+' . $day . '\s+' . $hour . '\s+[0-5]?\d\s+[0-5]?\d';
        $partial = '(?:' . $year . '[-/]' . $month . '|' . $month . '/' . $year . ')';

        return $expressions = [
            'date' => '~' . $zoneDefinition . '(?<!\d)(?:' . $reversed . '|' . $spaced . '|' . $namedDateTime . '|' . $dateTime . ')(?!\d)~iu',
            'partial-date' => '~(?<!\d)' . $partial . '(?!\d)~u',
            'duration' => '~PT\d+H\d+M\d+S~iu',
            'fractional-unix' => '~(?<!\d)\d{9,11}\.\d{1,6}(?!\d)~u',
            'time' => '~' . $zoneDefinition . '(?<!\d)(?:' . $offset . '|' . $colonTime . '|' . $minute . ':' . $minute . ')(?:\s*[AP]M)?' . $zoneSuffix . '(?!\d)~iu',
            'named-date-part' => '~' . $namedMonth . '|' . $weekday . '|' . $day . '(?:st|nd|rd|th)~iu',
            'timezone' => '~' . $zoneDefinition . $zone . '~u',
        ];
    }
}
