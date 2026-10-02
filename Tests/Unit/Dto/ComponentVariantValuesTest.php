<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Dto;

use Andersundsehr\FrontendStudio\Dto\ComponentVariantValues;
use Andersundsehr\FrontendStudio\Transformer\Defaults\TypolinkTargetEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ComponentVariantValuesTest extends TestCase
{
    #[DataProvider('numericValuesDataProvider')]
    public function testNormalizesNumericValuesForDeclaredTypes(mixed $value, ?string $type, mixed $expected): void
    {
        $types = $type === null ? [] : ['value' => $type];

        self::assertSame(
            ['value' => $expected],
            ComponentVariantValues::fromYamlValues(['value' => $value])->normalizeForArgumentTypes($types)->toArray(),
        );
        self::assertSame(
            ['value' => $expected],
            ComponentVariantValues::fromSubmittedValues(['value' => $value], $types)->toArray(),
        );
    }

    /**
     * @return iterable<string, array{mixed, ?string, mixed}>
     */
    public static function numericValuesDataProvider(): iterable
    {
        yield 'quoted integer' => ['50', 'int', 50];
        yield 'integer alias' => ['50', 'integer', 50];
        yield 'quoted decimal' => ['1.5', 'float', 1.5];
        yield 'float alias' => ['1.5', 'double', 1.5];
        yield 'zero integer' => ['0', 'int', 0];
        yield 'zero float' => ['0', 'float', 0.0];
        yield 'negative integer' => ['-1', 'int', -1];
        yield 'negative decimal' => ['-1.5', 'float', -1.5];
        yield 'large float' => ['1.0E+20', 'float', 1.0E+20];
        yield 'small float' => ['1.0E-20', 'double', 1.0E-20];
        yield 'native integer' => [50, 'int', 50];
        yield 'native decimal' => [1.5, 'float', 1.5];
        yield 'integer for float' => [50, 'float', 50.0];
        yield 'decimal for integer' => [1.5, 'integer', 1];
        yield 'leading zeros for integer' => ['0050', 'int', 50];
        yield 'maximum integer' => [(string)PHP_INT_MAX, 'int', PHP_INT_MAX];
        yield 'minimum integer' => [(string)PHP_INT_MIN, 'integer', PHP_INT_MIN];
        yield 'null integer' => [null, 'int', null];
        yield 'null float' => [null, 'float', null];
        yield 'empty integer' => ['', 'integer', ''];
        yield 'empty float' => ['', 'double', ''];
        yield 'invalid integer' => ['invalid', 'int', 'invalid'];
        yield 'invalid float' => ['1.5suffix', 'float', '1.5suffix'];
        yield 'numeric string' => ['50', 'string', '50'];
        yield 'string with leading zeros' => ['0050', 'string', '0050'];
        yield 'mixed string' => ['50', 'mixed', '50'];
        yield 'unknown type' => ['50', null, '50'];
        yield 'nullable integer' => ['50', '?int', '50'];
        yield 'union type' => ['50', 'int|string', '50'];
    }

    public function testNormalizesNestedNumericTransformerInputs(): void
    {
        $values = ComponentVariantValues::fromYamlValues([
            'content' => ['count' => '50', 'ratio' => '1.5', 'label' => '0050'],
        ])->normalizeForArgumentTypes([
            'content' => 'string',
            'content.count' => 'int',
            'content.ratio' => 'double',
            'content.label' => 'string',
        ]);

        self::assertSame(['content' => ['count' => 50, 'ratio' => 1.5, 'label' => '0050']], $values->toArray());
    }

    public function testPersistsNormalizedValuesAsYamlNumbers(): void
    {
        $values = ComponentVariantValues::fromYamlValues(['count' => '50', 'ratio' => '1.5'])->normalizeForArgumentTypes([
            'count' => 'int',
            'ratio' => 'float',
        ]);

        self::assertSame(['count' => 50, 'ratio' => 1.5], Yaml::parse(Yaml::dump($values->toYamlArray())));
    }

    public function testConvertsExistingDirectAndTransformerEnumNamesToEnumValues(): void
    {
        $values = ComponentVariantValues::fromYamlValues([
            'status' => 'none',
            'link' => ['target' => 'none'],
        ])->normalizeForArgumentTypes([
            'status' => TypolinkTargetEnum::class,
            'link' => 'array',
            'link.target' => TypolinkTargetEnum::class,
        ]);

        $yaml = Yaml::dump($values->toYamlArray());

        self::assertStringContainsString('!php/enum', $yaml);
        self::assertSame([
            'status' => TypolinkTargetEnum::none,
            'link' => ['target' => TypolinkTargetEnum::none],
        ], Yaml::parse($yaml, Yaml::PARSE_CONSTANT));
    }
}
