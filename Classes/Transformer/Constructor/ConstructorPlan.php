<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Transformer\Constructor;

use InvalidArgumentException;

use function array_key_exists;
use function ltrim;
use function sprintf;

/**
 * How Frontend Studio builds a value object from its constructor.
 *
 * @internal
 */
final readonly class ConstructorPlan
{
    public function __construct(
        /** @var class-string */
        public string $className,
        /** @var array<string, ConstructorParameter> */
        public array $parameters,
    ) {
    }

    /**
     * @param array<array-key, mixed> $inputs
     */
    public function build(array $inputs, string $path = ''): object
    {
        $arguments = [];
        foreach ($this->parameters as $name => $parameter) {
            $parameterPath = ltrim($path . '.' . $name, '.');
            if (array_key_exists($name, $inputs)) {
                $arguments[$name] = $parameter->convert($inputs[$name], $parameterPath);
                continue;
            }

            if ($parameter->optional) {
                continue;
            }

            if ($parameter->object === null) {
                throw new InvalidArgumentException(sprintf('Missing input "%s" for %s.', $parameterPath, $this->className), 1763340001);
            }

            // A required nested object without fixture values may still be complete through its own defaults.
            $arguments[$name] = $parameter->object->build([], $parameterPath);
        }

        return new ($this->className)(...$arguments);
    }
}
