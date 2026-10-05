<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Dto;

use Andersundsehr\FrontendStudio\Dto\ComponentArgumentMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantValueMetadata;
use PHPUnit\Framework\TestCase;

final class ComponentTypeLabelTest extends TestCase
{
    public function testTypeLabelsRetainUnionNullableAndArraySyntax(): void
    {
        $type = '?Acme\Domain\Item[]|array<Acme\Domain\Other>|null';
        $expected = '?Item[]|array<Other>|null';
        $argument = new ComponentArgumentMetadata('items', $type, '', false, '', 'default', []);
        $value = new ComponentVariantValueMetadata('items', $type, '', '', null, false, false, false);
        self::assertSame($expected, $argument->getShortType());
        self::assertSame($expected, $value->getShortType());
        self::assertSame($type, $value->type);
    }
}
