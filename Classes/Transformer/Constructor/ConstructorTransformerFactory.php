<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Transformer\Constructor;

use Andersundsehr\FrontendStudio\Control\Attribute\Control;
use Andersundsehr\FrontendStudio\Transformer\Transformer;
use Andersundsehr\FrontendStudio\Transformer\TransformersFactory;
use phpDocumentor\Reflection\PseudoType;
use phpDocumentor\Reflection\Type;
use phpDocumentor\Reflection\TypeResolver;
use phpDocumentor\Reflection\Types\AbstractList;
use phpDocumentor\Reflection\Types\Boolean;
use phpDocumentor\Reflection\Types\Compound;
use phpDocumentor\Reflection\Types\ContextFactory;
use phpDocumentor\Reflection\Types\Float_;
use phpDocumentor\Reflection\Types\Integer;
use phpDocumentor\Reflection\Types\Null_;
use phpDocumentor\Reflection\Types\Nullable;
use phpDocumentor\Reflection\Types\Object_;
use phpDocumentor\Reflection\Types\String_;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use Throwable;
use TYPO3Fluid\Fluid\Core\ViewHelper\ArgumentDefinition;

use function array_filter;
use function array_map;
use function array_values;
use function class_exists;
use function count;
use function ctype_space;
use function enum_exists;
use function in_array;
use function ltrim;
use function preg_match;
use function preg_quote;
use function strlen;
use function strpos;
use function substr;

/**
 * Derives a transformer from the public constructor of a value object.
 *
 * Scalars, enums and dates become inputs. Nested value objects are flattened into dotted inputs
 * such as "link.url". Arrays become one input; when PHPDoc documents their element type,
 * each element is converted to it.
 */
final readonly class ConstructorTransformerFactory
{
    /**
     * Returns null when a parameter cannot be edited as a control, for example a service,
     * a variadic parameter or an ambiguous union type. Such classes still need a hand-written transformer.
     */
    public function create(string $type): ?Transformer
    {
        $plan = $this->plan($type, []);
        if ($plan === null) {
            return null;
        }

        $arguments = [];
        $controls = [];
        $this->collectInputs($plan, '', true, $arguments, $controls);

        return new Transformer(
            transformer: static fn(mixed ...$inputs): object => $plan->build($inputs),
            from: $plan->className . '::__construct',
            returnType: $plan->className,
            arguments: $arguments,
            services: [],
            controls: $controls,
        );
    }

    /**
     * @param array<string, true> $parents classes currently being planned, to stop on recursive value objects
     */
    private function plan(string $type, array $parents): ?ConstructorPlan
    {
        if (!class_exists($type) || isset($parents[ltrim($type, '\\')])) {
            return null;
        }

        $class = new ReflectionClass($type);
        $constructor = $class->getConstructor();
        if (!$class->isInstantiable() || $constructor === null || $constructor->getNumberOfParameters() === 0) {
            return null;
        }

        $parents[$class->getName()] = true;
        $parameters = [];
        foreach ($constructor->getParameters() as $parameter) {
            $plannedParameter = $this->planParameter($parameter, $parents);
            if ($plannedParameter === null) {
                return null;
            }

            $parameters[$parameter->getName()] = $plannedParameter;
        }

        return new ConstructorPlan($class->getName(), $parameters);
    }

    /**
     * @param array<string, true> $parents
     */
    private function planParameter(ReflectionParameter $parameter, array $parents): ?ConstructorParameter
    {
        if ($parameter->isVariadic()) {
            return null;
        }

        [$type, $nullable] = $this->getNativeType($parameter);
        if ($type === null) {
            return null;
        }

        $name = $parameter->getName();
        $optional = $parameter->isOptional();
        $defaultValue = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;
        $control = null;
        foreach ($parameter->getAttributes(Control::class) as $attribute) {
            $control = $attribute->newInstance()->id;
        }

        if ($type === 'array') {
            $elementType = $this->getElementType($parameter);
            if ($elementType === null || in_array($elementType, TransformersFactory::DEFAULT_SUPPORTED_TYPES, true) || enum_exists($elementType)) {
                return ConstructorParameter::list($name, $optional, $nullable, $defaultValue, $control, $elementType, null);
            }

            $elementObject = $this->plan($elementType, $parents);
            if ($elementObject === null) {
                return null;
            }

            return ConstructorParameter::list($name, $optional, $nullable, $defaultValue, $control, $elementType, $elementObject);
        }

        if (in_array($type, TransformersFactory::DEFAULT_SUPPORTED_TYPES, true) || enum_exists($type)) {
            return ConstructorParameter::input($name, $type, $optional, $nullable, $defaultValue, $control);
        }

        $object = $this->plan($type, $parents);
        if ($object === null) {
            return null;
        }

        return ConstructorParameter::object($name, $object, $optional, $nullable);
    }

    /**
     * A nullable parameter is edited with the control of its non-null type.
     *
     * @return array{0: string|null, 1: bool}
     */
    private function getNativeType(ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();
        $names = match (true) {
            $type instanceof ReflectionNamedType => [$type->getName()],
            $type instanceof ReflectionUnionType => array_map(
                static fn(object $part): string => $part instanceof ReflectionNamedType ? $part->getName() : '',
                $type->getTypes(),
            ),
            default => [],
        };
        $nonNullNames = array_values(array_filter($names, static fn(string $name): bool => $name !== 'null'));
        if (count($nonNullNames) !== 1 || $nonNullNames[0] === '') {
            return [null, false];
        }

        return [$nonNullNames[0], $type?->allowsNull() ?? false];
    }

    /**
     * Reads the element type of an array parameter from `@param` on the constructor
     * or `@var` on the promoted property. Returns null when it is undocumented or not a single type.
     */
    private function getElementType(ReflectionParameter $parameter): ?string
    {
        $class = $parameter->getDeclaringClass();
        if ($class === null) {
            return null;
        }

        $docType = $this->findTagType((string)$parameter->getDeclaringFunction()->getDocComment(), '@param', $parameter->getName());
        if ($docType === null && $parameter->isPromoted() && $class->hasProperty($parameter->getName())) {
            $docType = $this->findTagType((string)$class->getProperty($parameter->getName())->getDocComment(), '@var', null);
        }

        if ($docType === null) {
            return null;
        }

        try {
            $resolved = new TypeResolver()->resolve($docType, new ContextFactory()->createFromReflector($class));
        } catch (Throwable) {
            return null;
        }

        $list = $this->withoutNull($resolved);
        if (!$list instanceof AbstractList) {
            return null;
        }

        $element = $this->withoutNull($list->getValueType());
        while ($element instanceof PseudoType) {
            $element = $element->underlyingType();
        }

        return match (true) {
            $element instanceof Object_ && $element->getFqsen() !== null => ltrim((string)$element->getFqsen(), '\\'),
            $element instanceof String_ => 'string',
            $element instanceof Integer => 'int',
            $element instanceof Float_ => 'float',
            $element instanceof Boolean => 'bool',
            default => null,
        };
    }

    private function withoutNull(Type $type): ?Type
    {
        if ($type instanceof Nullable) {
            return $type->getActualType();
        }

        if (!$type instanceof Compound) {
            return $type;
        }

        $types = array_values(array_filter(iterator_to_array($type), static fn(Type $part): bool => !$part instanceof Null_));

        return count($types) === 1 ? $types[0] : null;
    }

    /**
     * Finds the type of a `@param $name` or `@var` tag. Generic types may contain spaces, such as `array<string, Link>`.
     */
    private function findTagType(string $docComment, string $tag, ?string $variable): ?string
    {
        $offset = 0;
        while (($position = strpos($docComment, $tag, $offset)) !== false) {
            $offset = $position + strlen($tag);
            $rest = ltrim(substr($docComment, $offset), " \t");
            $type = $this->readType($rest);
            if ($type === '') {
                continue;
            }

            $afterType = ltrim(substr($rest, strlen($type)), " \t");
            if ($variable === null || preg_match('/^&?\s*(?:\.\.\.)?\$' . preg_quote($variable, '/') . '\b/', $afterType) === 1) {
                return $type;
            }
        }

        return null;
    }

    private function readType(string $text): string
    {
        $depth = 0;
        $length = strlen($text);
        for ($index = 0; $index < $length; $index++) {
            $character = $text[$index];
            if (in_array($character, ['<', '(', '{', '['], true)) {
                $depth++;
            } elseif (in_array($character, ['>', ')', '}', ']'], true)) {
                $depth--;
            } elseif ($depth === 0 && ctype_space($character)) {
                break;
            }
        }

        return substr($text, 0, $index);
    }

    /**
     * @param array<string, ArgumentDefinition> $arguments
     * @param array<string, string> $controls
     */
    private function collectInputs(ConstructorPlan $plan, string $prefix, bool $required, array &$arguments, array &$controls): void
    {
        foreach ($plan->parameters as $name => $parameter) {
            $path = $prefix . $name;
            $isRequired = $required && !$parameter->optional;
            if ($parameter->object !== null) {
                $this->collectInputs($parameter->object, $path . '.', $isRequired, $arguments, $controls);
                continue;
            }

            if ($parameter->control !== null) {
                $controls[$path] = $parameter->control;
            }

            $arguments[$path] = new ArgumentDefinition(
                name: $path,
                type: $parameter->type,
                description: $parameter->elementType !== null ? 'List of ' . $parameter->elementType : '',
                required: $isRequired,
                defaultValue: $parameter->defaultValue,
                escape: false,
            );
        }
    }
}
