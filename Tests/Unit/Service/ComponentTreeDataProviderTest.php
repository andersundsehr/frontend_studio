<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\Service;

use Andersundsehr\FrontendStudio\Service\ComponentTreeDataProvider;
use Andersundsehr\FrontendStudio\Transformer\Defaults\TypolinkTargetEnum;
use Andersundsehr\FrontendStudio\Transformer\Transformer;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Yaml\Yaml;
use TYPO3Fluid\Fluid\Core\ViewHelper\ArgumentDefinition;

final class ComponentTreeDataProviderTest extends TestCase
{
    public function testCreatesLocalDateTimeImmutableDefault(): void
    {
        $subject = (new ReflectionClass(ComponentTreeDataProvider::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ComponentTreeDataProvider::class, 'getDefaultValueForArgumentType');
        $default = $method->invoke($subject, DateTimeImmutable::class);

        self::assertIsString($default);
        self::assertMatchesRegularExpression('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}$/', $default);
    }

    public function testCreatesFirstEnumCaseDefault(): void
    {
        $subject = (new ReflectionClass(ComponentTreeDataProvider::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ComponentTreeDataProvider::class, 'getDefaultValueForArgumentType');

        self::assertSame(TypolinkTargetEnum::none, $method->invoke($subject, TypolinkTargetEnum::class));
    }

    public function testStoresTransformerEnumDefaultAsEnum(): void
    {
        $subject = (new ReflectionClass(ComponentTreeDataProvider::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ComponentTreeDataProvider::class, 'getTransformerDefaults');
        $transformer = new Transformer(
            static fn(): string => '',
            'test',
            'string',
            ['target' => new ArgumentDefinition(
                'target',
                TypolinkTargetEnum::class,
                '',
                false,
                TypolinkTargetEnum::none,
                false,
            )],
            [],
        );

        self::assertSame(['target' => TypolinkTargetEnum::none], $method->invoke($subject, $transformer));
    }

    public function testRoundTripsEnumDefaultThroughYaml(): void
    {
        $yaml = Yaml::dump(['target' => TypolinkTargetEnum::none]);

        self::assertStringContainsString('!php/enum', $yaml);
        self::assertSame(TypolinkTargetEnum::none, Yaml::parse($yaml, Yaml::PARSE_CONSTANT)['target']);
    }
}
