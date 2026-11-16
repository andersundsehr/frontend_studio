<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service;

use Andersundsehr\FrontendStudio\Service\ComponentVariantTransformer;
use Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures\LinkTarget;
use Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures\TeaserCard;
use Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures\TeaserLink;
use Andersundsehr\FrontendStudio\Tests\Unit\Transformer\Fixtures\TeaserList;
use Andersundsehr\FrontendStudio\Transformer\Defaults\TypolinkTargetEnum;
use Andersundsehr\FrontendStudio\Transformer\Transformer;
use Andersundsehr\FrontendStudio\Transformer\TransformerFactory;
use Andersundsehr\FrontendStudio\Transformer\Transformers;
use DateTime;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
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

    public function testPreservesEnumTransformerInput(): void
    {
        $definition = new ComponentDefinition('test:component', [
            'result' => new ArgumentDefinition('result', TypolinkTargetEnum::class, '', true, null, false),
        ], false, []);
        $transformer = new Transformer(
            static fn(TypolinkTargetEnum $target): TypolinkTargetEnum => $target,
            'test',
            TypolinkTargetEnum::class,
            ['target' => new ArgumentDefinition('target', TypolinkTargetEnum::class, '', true, null, false)],
            [],
        );

        $result = new ComponentVariantTransformer()->transform(
            $definition,
            new Transformers(['result' => $transformer], 'test'),
            ['result' => ['target' => TypolinkTargetEnum::none]],
        );

        self::assertSame(TypolinkTargetEnum::none, $result['result']);
    }

    public function testBuildsObjectFromConstructorTransformer(): void
    {
        $definition = new ComponentDefinition('test:component', [
            'link' => new ArgumentDefinition('link', TeaserLink::class, '', true, null, false),
        ], false, []);
        $transformer = new TransformerFactory(new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new RuntimeException('No services available in unit test container.', 1763333102);
            }

            public function has(string $id): bool
            {
                return false;
            }
        })->fromConstructor(TeaserLink::class);
        self::assertNotNull($transformer);

        $result = new ComponentVariantTransformer()->transform($definition, new Transformers(['link' => $transformer], 'test'), [
            'link' => ['url' => '/news', 'target' => 'Blank', 'position' => '2'],
        ]);

        self::assertEquals(new TeaserLink('/news', LinkTarget::Blank, null, 2), $result['link']);
    }

    public function testBuildsNestedObjectsAndListsFromFixtureMaps(): void
    {
        $definition = new ComponentDefinition('test:component', [
            'card' => new ArgumentDefinition('card', TeaserCard::class, '', true, null, false),
            'list' => new ArgumentDefinition('list', TeaserList::class, '', true, null, false),
        ], false, []);
        $factory = new TransformerFactory(new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new RuntimeException('No services available in unit test container.', 1763340101);
            }

            public function has(string $id): bool
            {
                return false;
            }
        });
        $cardTransformer = $factory->fromConstructor(TeaserCard::class);
        $listTransformer = $factory->fromConstructor(TeaserList::class);
        self::assertNotNull($cardTransformer);
        self::assertNotNull($listTransformer);

        $result = new ComponentVariantTransformer()->transform($definition, new Transformers(['card' => $cardTransformer, 'list' => $listTransformer], 'test'), [
            'card' => ['headline' => 'News', 'link' => ['url' => '/news', 'target' => 'Blank', 'position' => '1']],
            'list' => ['headline' => 'All', 'links' => [['url' => '/a'], ['url' => '/b', 'target' => 'Blank']]],
        ]);

        self::assertEquals(new TeaserCard('News', new TeaserLink('/news', LinkTarget::Blank, null, 1)), $result['card']);
        self::assertEquals(
            new TeaserList('All', [new TeaserLink('/a'), new TeaserLink('/b', LinkTarget::Blank)]),
            $result['list'],
        );
    }
}
