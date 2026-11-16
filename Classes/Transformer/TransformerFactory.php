<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Transformer;

use Closure;
use Andersundsehr\FrontendStudio\Control\Attribute\Control;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use TYPO3Fluid\Fluid\Core\ViewHelper\ArgumentDefinition;

use function array_filter;
use function array_map;
use function array_values;
use function class_exists;
use function count;
use function enum_exists;
use function in_array;
use function sprintf;

final readonly class TransformerFactory
{
    public function __construct(private ContainerInterface $container)
    {
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
     * Derives a transformer from the public constructor of a class.
     *
     * Every constructor parameter becomes a fixture input, so the class needs no transformer file.
     * Returns null when a parameter cannot be edited as a control, for example a service, a nested object,
     * a variadic parameter or an ambiguous union type. Such classes still need a hand-written transformer.
     */
    public function fromConstructor(string $type): ?Transformer
    {
        if (!class_exists($type)) {
            return null;
        }

        $class = new ReflectionClass($type);
        $constructor = $class->getConstructor();
        if (!$class->isInstantiable() || $constructor === null || $constructor->getNumberOfParameters() === 0) {
            return null;
        }

        $arguments = [];
        $controls = [];
        foreach ($constructor->getParameters() as $parameter) {
            $inputType = $this->getConstructorInputType($parameter);
            if ($inputType === null) {
                return null;
            }

            $argumentName = $parameter->getName();
            foreach ($parameter->getAttributes(Control::class) as $attribute) {
                $controls[$argumentName] = $attribute->newInstance()->id;
            }

            $arguments[$argumentName] = $this->createInput($parameter, $inputType);
        }

        $className = $class->getName();

        return new Transformer(
            transformer: static fn(mixed ...$arguments): object => new $className(...$arguments),
            from: $className . '::__construct',
            returnType: $className,
            arguments: $arguments,
            services: [],
            controls: $controls,
        );
    }

    /**
     * A nullable parameter is edited with the control of its non-null type.
     */
    private function getConstructorInputType(ReflectionParameter $parameter): ?string
    {
        if ($parameter->isVariadic()) {
            return null;
        }

        $type = $parameter->getType();
        $names = match (true) {
            $type instanceof ReflectionNamedType => [$type->getName()],
            $type instanceof ReflectionUnionType => array_map(
                static fn(object $part): string => $part instanceof ReflectionNamedType ? $part->getName() : '',
                $type->getTypes(),
            ),
            default => [],
        };
        $names = array_values(array_filter($names, static fn(string $name): bool => $name !== 'null'));
        if (count($names) !== 1) {
            return null;
        }

        $name = $names[0];
        if (in_array($name, TransformersFactory::DEFAULT_SUPPORTED_TYPES, true) || enum_exists($name)) {
            return $name;
        }

        return null;
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
