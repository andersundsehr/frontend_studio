<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service\Snapshot;

use Andersundsehr\FrontendStudio\Service\Snapshot\SamplingClock;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SamplingClockTest extends TestCase
{
    #[DataProvider('dates')]
    public function testAdvancesEveryPartWithoutCarryingIntoOtherParts(string $first, string $expected, string $timezone): void
    {
        $date = new DateTimeImmutable($first, new DateTimeZone($timezone));
        $second = SamplingClock::advance($date);
        self::assertSame($expected, $second->format('Y-m-d H:i:s.u'));
        self::assertSame($timezone, $second->getTimezone()->getName());
        self::assertSame($first, $date->format('Y-m-d H:i:s.u'));
        foreach (['Y', 'n', 'j', 'G', 'i', 's', 'u'] as $part) {
            self::assertNotSame($date->format($part), $second->format($part), 'Date part ' . $part . ' must change.');
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function dates(): iterable
    {
        yield 'ordinary date' => ['2026-10-07 14:10:01.000000', '2027-11-08 15:11:02.000001', 'UTC'];
        yield 'December wraps independently of year' => ['2026-12-07 10:00:00.000000', '2027-01-08 11:01:01.000001', 'UTC'];
        yield 'all clock parts wrap without carry' => ['2026-12-31 23:59:59.999999', '2027-01-01 00:00:00.000000', 'UTC'];
        yield 'shorter month wraps day' => ['2026-03-31 14:10:01.123456', '2027-04-01 15:11:02.123457', 'UTC'];
        yield 'February in leap year accepts day 29' => ['2027-01-28 14:10:01.000000', '2028-02-29 15:11:02.000001', 'UTC'];
        yield 'February in ordinary year wraps day 29' => ['2026-01-28 14:10:01.000000', '2027-02-01 15:11:02.000001', 'UTC'];
        yield 'January day 31 does not overflow February' => ['2026-01-31 14:10:01.000000', '2027-02-01 15:11:02.000001', 'UTC'];
        yield 'leap day advances into March' => ['2024-02-29 14:10:01.000000', '2025-03-30 15:11:02.000001', 'UTC'];
        yield 'timezone is preserved' => ['2026-10-07 14:10:01.000000', '2027-11-08 15:11:02.000001', 'Europe/Berlin'];
        yield 'nonexistent DST hour is normalized' => ['2025-02-28 01:10:01.000000', '2026-03-29 03:11:02.000001', 'Europe/Berlin'];
    }
}
