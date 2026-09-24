<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Dto;

use Andersundsehr\FrontendStudio\Dto\ComponentVariantValues;
use Andersundsehr\FrontendStudio\Transformer\Defaults\TypolinkTargetEnum;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ComponentVariantValuesTest extends TestCase
{
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
