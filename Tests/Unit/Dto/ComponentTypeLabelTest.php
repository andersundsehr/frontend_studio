<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Dto;

use Andersundsehr\FrontendStudio\Dto\ComponentArgumentMetadata;
use Andersundsehr\FrontendStudio\Dto\ComponentVariantValueMetadata;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ComponentTypeLabelTest extends TestCase
{
    #[DataProvider('typeLabels')]
    public function testTypeLabelsRetainUnionNullableAndArraySyntax(string $type, string $expected): void
    {
        $argument = new ComponentArgumentMetadata('items', $type, '', false, '', 'default', []);
        $value = new ComponentVariantValueMetadata('items', $type, '', '', null, false, false, false);
        self::assertSame($expected, $argument->getShortType());
        self::assertSame($expected, $value->getShortType());
        self::assertSame($type, $value->type);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function typeLabels(): iterable
    {
        yield 'relative namespaces' => ['?Acme\Domain\Item[]|array<Acme\Domain\Other>|null', '?Item[]|array<Other>|null'];
        yield 'absolute namespace' => ['\Acme\Domain\Item', 'Item'];
        yield 'global types' => ['?\DateTime|\Stringable|null', '?\DateTime|\Stringable|null'];
        yield 'mixed types' => ['\Acme\Item[]|array<\Acme\Other>|\DateTime', 'Item[]|array<Other>|\DateTime'];
    }
}
