<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\ConfigurationModuleProvider;

use Andersundsehr\FrontendStudio\ConfigurationModuleProvider\TypeTransformersProvider;
use Andersundsehr\FrontendStudio\Control\Attribute\Control;
use Andersundsehr\FrontendStudio\Tests\Functional\Fixtures\ConfigurationModuleTransformer;
use Andersundsehr\FrontendStudio\Transformer\Defaults\TypolinkTargetEnum;
use Andersundsehr\FrontendStudio\Transformer\TransformerFactory;
use Andersundsehr\FrontendStudio\Transformer\TypeTransformers;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;
use Stringable;

final class TypeTransformersProviderTest extends TestCase
{
    public function testEmptyRegistryHasNoConfiguration(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $registry = new TypeTransformers(new TransformerFactory($container));

        self::assertSame([], new TypeTransformersProvider($registry, $container)->getConfiguration());
    }

    public function testListsAllRegistrationsWithInputsControlsAndServiceTypesWithoutExecutingThem(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn(string $id): bool => $id === stdClass::class);
        $container->method('get')->willReturn(new stdClass());
        $registry = new TypeTransformers(new TransformerFactory($container));
        $handler = new class {
            /** @param array<string, mixed> $options */
            public function transform(
                stdClass $service,
                #[Control('custom.text')] string $value,
                string $label = 'Default',
                TypolinkTargetEnum $target = TypolinkTargetEnum::_blank,
                array $options = [],
            ): Stringable {
                throw new RuntimeException('Listing transformers must not execute them.', 3793151532);
            }
        };
        foreach ([200, 200, 100] as $priority) {
            $registry->addTransformer($handler, 'transform', Stringable::class, $priority);
        }

        // Resolved aliases must not appear as additional registrations.
        $registry->get('string|Stringable');
        $subject = new TypeTransformersProvider($registry, $container);
        $configuration = $subject->getConfiguration();

        self::assertSame([Stringable::class], array_keys($configuration));
        self::assertCount(3, $configuration[Stringable::class]);
        self::assertSame(['false', 'true', 'false'], array_column($configuration[Stringable::class], 'active'));
        foreach ($configuration[Stringable::class] as $index => $registration) {
            self::assertSame($handler::class, $registration['handler']);
            self::assertSame('transform', $registration['method']);
            self::assertSame(Stringable::class, $registration['returnType']);
            self::assertSame([200, 200, 100][$index], $registration['priority']);
            self::assertSame([
                'value' => ['type' => 'string', 'required' => 'true', 'defaultValue' => '(no default)', 'control' => 'custom.text'],
                'label' => ['type' => 'string', 'required' => 'false', 'defaultValue' => 'Default'],
                'target' => ['type' => TypolinkTargetEnum::class, 'required' => 'false', 'defaultValue' => TypolinkTargetEnum::class . '::_blank'],
                'options' => ['type' => 'array', 'required' => 'false', 'defaultValue' => []],
            ], $registration['arguments']);
            self::assertSame(['service' => stdClass::class], $registration['services']);
        }

        self::assertSame($configuration, $subject->getConfiguration());
    }

    public function testFormatsDefaultsWithoutChangingTransformerArgumentsOrExecution(): void
    {
        $container = $this->createStub(ContainerInterface::class);
        $registry = new TypeTransformers(new TransformerFactory($container));
        $registry->addTransformer(new ConfigurationModuleTransformer(), 'transform', 'array', 100);

        $configuration = new TypeTransformersProvider($registry, $container)->getConfiguration();
        $arguments = $configuration['array'][0]['arguments'];

        $expectedDefaults = [
            'value' => '(no default)',
            'enabled' => 'true',
            'disabled' => 'false',
            'nullable' => 'null',
            'empty' => "''",
            'count' => 5,
            'ratio' => 1.5,
            'target' => TypolinkTargetEnum::class . '::none',
            'options' => [
                'enabled' => 'true',
                'disabled' => 'false',
                'nullable' => 'null',
                'empty' => "''",
                'nested' => [
                    'values' => ['true', 'false', 'null', "''", 0, 1.5, 'value', TypolinkTargetEnum::class . '::_blank', TypolinkTargetEnum::class . '::none'],
                    'emptyArray' => [],
                ],
            ],
        ];
        foreach ($expectedDefaults as $name => $expectedDefault) {
            self::assertSame($expectedDefault, $arguments[$name]['defaultValue']);
        }

        self::assertSame('true', $arguments['value']['required']);
        foreach (array_slice($arguments, 1) as $argument) {
            self::assertSame('false', $argument['required']);
        }

        $transformer = $registry->get('array');
        self::assertTrue($transformer->arguments['enabled']->getDefaultValue());
        self::assertFalse($transformer->arguments['disabled']->getDefaultValue());
        self::assertNull($transformer->arguments['nullable']->getDefaultValue());
        self::assertSame('', $transformer->arguments['empty']->getDefaultValue());
        $nativeOptions = [
            'enabled' => true,
            'disabled' => false,
            'nullable' => null,
            'empty' => '',
            'nested' => [
                'values' => [true, false, null, '', 0, 1.5, 'value', TypolinkTargetEnum::_blank, TypolinkTargetEnum::none],
                'emptyArray' => [],
            ],
        ];
        self::assertSame($nativeOptions, $transformer->arguments['options']->getDefaultValue());
        self::assertSame([
            'value' => 'Example',
            'enabled' => true,
            'disabled' => false,
            'nullable' => null,
            'empty' => '',
            'count' => 5,
            'ratio' => 1.5,
            'target' => TypolinkTargetEnum::none,
            'options' => $nativeOptions,
        ], $transformer->execute(['value' => 'Example']));
    }
}
