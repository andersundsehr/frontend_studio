<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Unit\ConfigurationModuleProvider;

use Andersundsehr\FrontendStudio\ConfigurationModuleProvider\TypeTransformersProvider;
use Andersundsehr\FrontendStudio\Control\Attribute\Control;
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
        self::assertSame([false, true, false], array_column($configuration[Stringable::class], 'active'));
        foreach ($configuration[Stringable::class] as $index => $registration) {
            self::assertSame($handler::class, $registration['handler']);
            self::assertSame('transform', $registration['method']);
            self::assertSame(Stringable::class, $registration['returnType']);
            self::assertSame([200, 200, 100][$index], $registration['priority']);
            self::assertSame([
                'value' => ['type' => 'string', 'required' => true, 'defaultValue' => null, 'control' => 'custom.text'],
                'label' => ['type' => 'string', 'required' => false, 'defaultValue' => 'Default'],
                'target' => ['type' => TypolinkTargetEnum::class, 'required' => false, 'defaultValue' => TypolinkTargetEnum::_blank],
                'options' => ['type' => 'array', 'required' => false, 'defaultValue' => []],
            ], $registration['arguments']);
            self::assertSame(['service' => stdClass::class], $registration['services']);
        }

        self::assertSame($configuration, $subject->getConfiguration());
    }
}
