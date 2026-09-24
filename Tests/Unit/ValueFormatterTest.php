<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit;

use Andersundsehr\FrontendStudio\ValueFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use JsonSerializable;
use RuntimeException;

#[CoversClass(ValueFormatter::class)]
final class ValueFormatterTest extends TestCase
{
    public function testFormatsValuesForDisplay(): void
    {
        self::assertSame('', ValueFormatter::format(null));
        self::assertSame('true', ValueFormatter::format(true));
        self::assertSame('42', ValueFormatter::format(42));
        self::assertSame("{\n    \"name\": \"Card\"\n}", ValueFormatter::format(['name' => 'Card']));
        self::assertSame("{\n    \"name\": \"Card\"\n}", ValueFormatter::format((object)['name' => 'Card']));

        $unserializableValue = new class implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                throw new RuntimeException();
            }
        };
        self::assertSame(get_debug_type($unserializableValue), ValueFormatter::format($unserializableValue));
    }
}
