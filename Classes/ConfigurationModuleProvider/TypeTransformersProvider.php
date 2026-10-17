<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\ConfigurationModuleProvider;

use Andersundsehr\FrontendStudio\Control\Attribute\Control;
use Andersundsehr\FrontendStudio\Transformer\TypeTransformers;
use Psr\Container\ContainerInterface;
use ReflectionMethod;
use TYPO3\CMS\Lowlevel\ConfigurationModuleProvider\AbstractProvider;

final class TypeTransformersProvider extends AbstractProvider
{
    public function __construct(
        private readonly TypeTransformers $typeTransformers,
        private readonly ContainerInterface $container,
    ) {
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function getConfiguration(): array
    {
        $configuration = [];
        foreach ($this->typeTransformers->getConfiguration()['raw'] as $returnType => $registrations) {
            $activeIndex = 0;
            foreach ($registrations as $index => $registration) {
                if ($registration['priority'] >= $registrations[$activeIndex]['priority']) {
                    $activeIndex = $index;
                }
            }

            foreach ($registrations as $index => $registration) {
                $arguments = [];
                $services = [];
                $method = new ReflectionMethod($registration['handler'], $registration['method']);
                foreach ($method->getParameters() as $parameter) {
                    $name = $parameter->getName();
                    $type = (string)$parameter->getType();
                    if ($this->container->has($type)) {
                        $services[$name] = $type;
                        continue;
                    }

                    $arguments[$name] = [
                        'type' => $type,
                        'required' => !$parameter->isOptional(),
                        'defaultValue' => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
                    ];
                    foreach ($parameter->getAttributes(Control::class) as $attribute) {
                        $arguments[$name]['control'] = $attribute->newInstance()->id;
                    }
                }

                $configuration[$returnType][] = [
                    ...$registration,
                    'active' => $index === $activeIndex,
                    'arguments' => $arguments,
                    'services' => $services,
                ];
            }
        }

        ksort($configuration);
        return $configuration;
    }
}
