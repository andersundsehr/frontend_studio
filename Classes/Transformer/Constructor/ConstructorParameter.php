<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Transformer\Constructor;

use Andersundsehr\FrontendStudio\Transformer\InputNormalizer;
use InvalidArgumentException;

use function is_array;
use function sprintf;

/**
 * One constructor parameter of a value object that Frontend Studio builds from fixture values.
 *
 * A parameter is either a single input (scalar, enum, date), a nested value object,
 * or an array whose elements are converted to the type documented in PHPDoc.
 *
 * @internal
 */
final readonly class ConstructorParameter
{
    private function __construct(
        public string $name,
        public string $type,
        public bool $optional,
        public bool $nullable,
        public mixed $defaultValue,
        public ?string $control,
        public ?ConstructorPlan $object = null,
        public bool $isList = false,
        public ?string $elementType = null,
        public ?ConstructorPlan $elementObject = null,
    ) {
    }

    public static function input(string $name, string $type, bool $optional, bool $nullable, mixed $defaultValue, ?string $control): self
    {
        return new self($name, $type, $optional, $nullable, $defaultValue, $control);
    }

    public static function object(string $name, ConstructorPlan $object, bool $optional, bool $nullable): self
    {
        return new self($name, $object->className, $optional, $nullable, null, null, $object);
    }

    /**
     * @param string|null $elementType PHPDoc element type of the array, null when undocumented
     */
    public static function list(
        string $name,
        bool $optional,
        bool $nullable,
        mixed $defaultValue,
        ?string $control,
        ?string $elementType,
        ?ConstructorPlan $elementObject,
    ): self {
        return new self($name, 'array', $optional, $nullable, $defaultValue, $control, null, true, $elementType, $elementObject);
    }

    public function convert(mixed $value, string $path): mixed
    {
        if ($value === null && $this->nullable) {
            return null;
        }

        if ($this->object !== null) {
            if (!is_array($value)) {
                throw new InvalidArgumentException(sprintf('Input "%s" must be a map of %s values.', $path, $this->object->className), 1763340002);
            }

            return $this->object->build($value, $path);
        }

        if (!$this->isList) {
            return InputNormalizer::normalize($this->type, $value);
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf('Input "%s" must be a list.', $path), 1763340003);
        }

        if ($this->elementType === null) {
            return $value;
        }

        foreach ($value as $key => $element) {
            $elementPath = $path . '.' . $key;
            if ($this->elementObject === null) {
                $value[$key] = InputNormalizer::normalize($this->elementType, $element);
                continue;
            }

            if (!is_array($element)) {
                throw new InvalidArgumentException(sprintf('Input "%s" must be a map of %s values.', $elementPath, $this->elementObject->className), 1763340004);
            }

            $value[$key] = $this->elementObject->build($element, $elementPath);
        }

        return $value;
    }
}
