<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service;

use Andersundsehr\FrontendStudio\Service\ComponentVariantTransformer;
use Andersundsehr\FrontendStudio\Transformer\Defaults\TypolinkTargetEnum;
use Andersundsehr\FrontendStudio\Transformer\Transformer;
use Andersundsehr\FrontendStudio\Transformer\Transformers;
use DateTime;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TYPO3Fluid\Fluid\Core\Component\ComponentDefinition;
use TYPO3Fluid\Fluid\Core\ViewHelper\ArgumentDefinition;

final class ComponentVariantTransformerTest extends TestCase
{
    public function testTransformsNestedTypedInputs(): void
    {
        $definition = new ComponentDefinition('test:component', [
            'result' => new ArgumentDefinition('result', 'string', '', true, null, false),
        ], false, []);
        $transformer = new Transformer(
            static fn(int $count, DateTimeImmutable $date, TypolinkTargetEnum $status): string => $count . $date->format('YH') . $date->getTimezone()->getName() . $status->value,
            'test',
            'string',
            [
                'count' => new ArgumentDefinition('count', 'int', '', true, null, false),
                'date' => new ArgumentDefinition('date', DateTimeImmutable::class, '', true, null, false),
                'status' => new ArgumentDefinition('status', TypolinkTargetEnum::class, '', true, null, false),
            ],
            [],
        );

        $result = new ComponentVariantTransformer()->transform($definition, new Transformers(['result' => $transformer], 'test'), [
            'result' => [
                'count' => '3',
                'date' => '2026-09-23T10:00:00+02:00',
                'status' => '_blank',
            ],
        ]);

        self::assertSame('3202610' . date_default_timezone_get() . '_blank', $result['result']);
    }

    public function testDateTimeInputPreservesFixtureHourInPhpTimezone(): void
    {
        $definition = new ComponentDefinition('test:component', [
            'result' => new ArgumentDefinition('result', DateTime::class, '', true, null, false),
        ], false, []);
        $transformer = new Transformer(
            static fn(DateTime $date): DateTime => $date,
            'test',
            DateTime::class,
            ['date' => new ArgumentDefinition('date', DateTime::class, '', true, null, false)],
            [],
        );

        $result = new ComponentVariantTransformer()->transform($definition, new Transformers(['result' => $transformer], 'test'), [
            'result' => ['date' => '2026-09-23T10:00:00Z'],
        ]);

        self::assertSame('10', $result['result']->format('H'));
        self::assertSame(date_default_timezone_get(), $result['result']->getTimezone()->getName());
    }

    public function testRejectsScalarTransformerValue(): void
    {
        $definition = new ComponentDefinition('test:component', [
            'result' => new ArgumentDefinition('result', 'string', '', true, null, false),
        ], false, []);
        $transformer = new Transformer(
            static fn(string $value): string => $value,
            'test',
            'string',
            ['value' => new ArgumentDefinition('value', 'string', '', true, null, false)],
            [],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(1768901555);

        new ComponentVariantTransformer()->transform($definition, new Transformers(['result' => $transformer], 'test'), ['result' => 'invalid']);
    }
}
