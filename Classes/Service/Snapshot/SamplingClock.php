<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service\Snapshot;

use DateTimeImmutable;

final readonly class SamplingClock
{
    public static function advance(DateTimeImmutable $date): DateTimeImmutable
    {
        $month = $date->setDate((int)$date->format('Y') + 1, (int)$date->format('n') % 12 + 1, 1);
        $day = (int)$date->format('j') + 1;
        if ($day > (int)$month->format('t')) {
            $day = 1;
        }

        return $month->setDate((int)$month->format('Y'), (int)$month->format('n'), $day)->setTime(
            ((int)$date->format('G') + 1) % 24,
            ((int)$date->format('i') + 1) % 60,
            ((int)$date->format('s') + 1) % 60,
            ((int)$date->format('u') + 1) % 1000000,
        );
    }
}
