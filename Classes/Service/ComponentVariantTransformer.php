<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use Andersundsehr\FrontendStudio\Transformer\Transformer;
use Andersundsehr\FrontendStudio\Transformer\Transformers;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use TYPO3Fluid\Fluid\Core\Component\ComponentDefinition;
use TYPO3Fluid\Fluid\Core\ViewHelper\ArgumentDefinition;

use function array_key_exists;
use function enum_exists;
use function filter_var;
use function in_array;
use function is_numeric;
use function is_string;

use const FILTER_VALIDATE_BOOL;

final readonly class ComponentVariantTransformer
{
    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function transform(ComponentDefinition $componentDefinition, Transformers $transformers, array $values): array
    {
        foreach ($transformers->arguments as $argumentName => $transformer) {
            $hasValue = array_key_exists($argumentName, $values);
            if ($hasValue && !is_array($values[$argumentName])) {
                throw new InvalidArgumentException('Transformer-backed argument "' . $argumentName . '" must be a nested fixture map.', 1768901555);
            }

            if (!$hasValue && $this->hasRequiredInput($transformer)) {
                if ($componentDefinition->getArgumentDefinitions()[$argumentName]->isRequired()) {
                    throw new InvalidArgumentException('Missing fixture inputs for required transformer-backed argument "' . $argumentName . '".', 1768901556);
                }

                continue;
            }

            $inputs = $hasValue ? $values[$argumentName] : [];
            foreach ($transformer->arguments as $inputName => $definition) {
                if (!array_key_exists($inputName, $inputs)) {
                    if ($definition->isRequired()) {
                        throw new InvalidArgumentException('Missing transformer input "' . $argumentName . '.' . $inputName . '".', 1768901557);
                    }

                    continue;
                }

                $inputs[$inputName] = $this->normalize($definition, $inputs[$inputName]);
            }

            $values[$argumentName] = $transformer->execute($inputs);
        }

        return $values;
    }

    private function hasRequiredInput(Transformer $transformer): bool
    {
        return array_any($transformer->arguments, fn(ArgumentDefinition $argument): bool => $argument->isRequired());
    }

    private function normalize(ArgumentDefinition $definition, mixed $value): mixed
    {
        $type = $definition->getType();
        if ($value === null || $type === 'mixed') {
            return $value;
        }

        if (enum_exists($type)) {
            foreach ($type::cases() as $case) {
                if ($case->name === $value) {
                    return $case;
                }
            }

            throw new InvalidArgumentException('Invalid enum case "' . $value . '" for "' . $type . '".', 1768901558);
        }

        if (in_array($type, [DateTime::class, DateTimeImmutable::class, DateTimeInterface::class], true)) {
            if (!is_string($value)) {
                throw new InvalidArgumentException('Date transformer input must be an ISO-8601 string.', 1768901559);
            }

            $value = preg_replace('/(?:Z|[+-]\d{2}:?\d{2})$/', '', $value) ?: $value;
            return $type === DateTime::class ? new DateTime($value, new DateTimeZone(date_default_timezone_get())) : new DateTimeImmutable($value, new DateTimeZone(date_default_timezone_get()));
        }

        return match ($type) {
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOL),
            'int', 'integer' => is_numeric($value) ? (int)$value : $value,
            'float', 'double' => is_numeric($value) ? (float)$value : $value,
            default => $value,
        };
    }
}
