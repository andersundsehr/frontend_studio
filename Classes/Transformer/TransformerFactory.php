<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Transformer;

use Closure;
use Andersundsehr\FrontendStudio\Control\Attribute\Control;
use Andersundsehr\FrontendStudio\Transformer\Constructor\ConstructorTransformerFactory;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use ReflectionFunction;
use ReflectionParameter;
use TYPO3Fluid\Fluid\Core\ViewHelper\ArgumentDefinition;

use function class_exists;
use function enum_exists;
use function in_array;
use function sprintf;

final readonly class TransformerFactory
{
    public function __construct(
        private ContainerInterface $container,
        private ConstructorTransformerFactory $constructorTransformerFactory = new ConstructorTransformerFactory(),
    ) {
    }

    public function fromCallable(callable $transformer, string $from): Transformer
    {
        $closure = Closure::fromCallable($transformer);
        $arguments = [];
        $reflection = new ReflectionFunction($closure);
        $returnType = $reflection->getReturnType()?->__toString() ?? throw new InvalidArgumentException(
            sprintf(
                'Transformer "%s" does not have a return type defined. Please define a return type for the transformer.',
                $reflection->getName(),
            ),
            5914437566,
        );
        $services = [];
        $controls = [];
        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType()?->__toString() ?? throw new InvalidArgumentException(
                sprintf(
                    'Argument "%s" for Transformer "%s" does not have a type defined. Please define a type for the argument.',
                    $parameter->getName(),
                    $from,
                ),
                9068759075,
            );
            if ($this->container->has($type)) {
                $services[$parameter->getName()] = $this->container->get($type);
                continue;
            }

            $argumentName = $parameter->getName();
            foreach ($parameter->getAttributes(Control::class) as $attribute) {
                $controls[$argumentName] = $attribute->newInstance()->id;
            }

            if (!in_array($type, TransformersFactory::DEFAULT_SUPPORTED_TYPES) && !class_exists($type) && !enum_exists($type)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Invalid type "%s" for argument "%s" in function "%s". Only basic types are allowed.',
                        $type,
                        $argumentName,
                        $reflection->getName()
                    ),
                    9211667105,
                );
            }

            $arguments[$argumentName] = $this->createInput($parameter, $type);
        }

        return new Transformer(
            transformer: $closure,
            from: $from,
            returnType: $returnType,
            arguments: $arguments,
            services: $services,
            controls: $controls
        );
    }

    /**
     * Derives a transformer from the public constructor of a value object, so the class needs no transformer file.
     * Returns null when a constructor parameter cannot be edited as a control.
     */
    public function fromConstructor(string $type): ?Transformer
    {
        return $this->constructorTransformerFactory->create($type);
    }

    private function createInput(ReflectionParameter $parameter, string $type): ArgumentDefinition
    {
        return new ArgumentDefinition(
            name: $parameter->getName(),
            type: $type,
            description: '',
            required: !$parameter->isOptional(),
            defaultValue: $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
            escape: false,
        );
    }
}
