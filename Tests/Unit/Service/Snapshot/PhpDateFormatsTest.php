<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service\Snapshot;

use Andersundsehr\FrontendStudio\Service\Snapshot\Comparison;
use Andersundsehr\FrontendStudio\Service\Snapshot\SamplingClock;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhpDateFormatsTest extends TestCase
{
    #[DataProvider('formats')]
    public function testFormattedDatesPreserveSurroundingContent(string $format): void
    {
        $date = new DateTimeImmutable('2026-12-07 10:00:00.123456', new DateTimeZone('Europe/Berlin'));
        $first = $date->format($format);
        $second = SamplingClock::advance($date)->format($format);
        $third = new DateTimeImmutable('2028-04-19 21:48:57.654321', new DateTimeZone('Pacific/Auckland'))->format($format);
        $comparison = new Comparison();
        foreach (['<p>Before[%s]After</p>', '<div data-value="Before[%s]After" class="stable">Hello</div>'] as $html) {
            $snapshot = $comparison->create(sprintf($html, $first), sprintf($html, $second));
            self::assertTrue($comparison->matches($snapshot, sprintf($html, $first)));
            self::assertTrue($comparison->matches($snapshot, sprintf($html, $second)));
            self::assertFalse($comparison->matches($snapshot, sprintf(str_replace('Before', 'Changed', $html), $second)));
            self::assertFalse($comparison->matches($snapshot, sprintf(str_replace('After', 'Changed', $html), $second)));
            if ($first === $second) {
                self::assertSame(sprintf($html, $first), $snapshot, 'Unchanged output must stay literal.');
                self::assertSame($first === $third, $comparison->matches($snapshot, sprintf($html, $third)));
                continue;
            }

            self::assertStringContainsString(Comparison::MARKER, $snapshot);
            // Doubled PHP escapes produce several date regions and literal backslashes.
            if (str_contains($format, '\\\\') || str_ends_with($format, 'Z UTC')) {
                continue;
            }

            $suffix = match ($format) {
                'Y-m-d|' => '|',
                'Ymd-His-' => '-',
                'Y-m-d H:i:s.' => '.',
                default => '',
            };
            self::assertSame(sprintf($html, Comparison::MARKER . $suffix), $snapshot, $first . ' -> ' . $second);
            self::assertTrue($comparison->matches($snapshot, sprintf($html, $third)));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function formats(): iterable
    {
        $formats = <<<'FORMATS'
U
Y-m-d
Ymd\THis\Z
\P\TH\Hi\Ms\S
c
D
d
D M d H:i:s Y
D M j H:i:s Y
D, d M Y H:i:s
D, d M Y H:i:s \G\M\T
D, d M Y H:i:s T
D, j M Y H:i:s
D,d M Y H:i:s
d-m-Y g:i:s
d-m-Y H:i:s
d/m/Y
d/m/y
d/m/Y H:i
e
F d, Y
F j
F j, Y
G
g:i A
H
H/i/s/d/m/Y
H:i
H:i e
H:i:s
h:i:s
Hi
His
i
i:s
j
j M Y
j-M-Y
j-M-Y H:i:s T
j-n-Y G:i:s
jS
jS M Y
L
l
m
M d H:i:s
M d Y H:i:s
M d, Y @ g:i:s a
M j
M j, Y
M j, Y H:i
m/d/Y
m/d/y
m/d/Y h:i:s
m/Y
n
P
r
s
t
u
U.u
U\.v
Uu
W
w
Y
y
Y n j G i s
Y-m
Y-m-d  h:i:s a
Y-m-d 00:00:00
Y-m-d G:i:s
Y-m-d H
Y-m-d H-i-s
Y-m-d H:00:00
Y-m-d H:i
Y-m-d H:i:59
Y-M-d H:i:s
Y-m-d H:i:s
Y-m-d H:i:s \\U\\T\\CP
Y-m-d H:i:s \G\M\T
Y-m-d H:i:s P
Y-m-d H:i:s T
Y-m-d H:i:s.
Y-m-d H:i:s.u
Y-m-d H:i:s.u T
Y-m-d H:i:s.v
Y-m-d H:iO
Y-m-d, H:i:s
Y-m-d-H
Y-m-d-H-i
Y-m-d-His
Y-m-d/H:i:s
Y-m-d\\TH:i:s
Y-m-d\\TH:i:s.uP
Y-m-d\TH:i:s
Y-m-d\TH:i:s T
Y-m-d\TH:i:s.000
Y-m-d\TH:i:s.u
Y-m-d\TH:i:s.u\Z
Y-m-d\TH:i:s.uP
Y-m-d\TH:i:s\Z
Y-m-d\TH:i:s\Z UTC
Y-m-d\TH:i:s\Z \U\T\C
Y-m-d_H-i
Y-m-d_Hi
Y-m-d_His_v
Y-m-d|
Y/m
Y/m/d
Y:m:d
Y_m_d_His
Ymd
Ymd-His-
Ymd\\THis\\Z
Ymd\TH:i:s
ymd_His
YmdGis
YmdHis
ymdHis
YmdHisZ
YYYY
Z
z
FORMATS;
        foreach (array_unique(explode("\n", $formats)) as $format) {
            yield $format => [$format];
        }
    }
}
