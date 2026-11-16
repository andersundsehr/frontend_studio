<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use Andersundsehr\FrontendStudio\Transformer\InputNormalizer;
use Andersundsehr\FrontendStudio\Transformer\InputPath;
use Andersundsehr\FrontendStudio\Transformer\Transformer;
use Andersundsehr\FrontendStudio\Transformer\Transformers;
use InvalidArgumentException;
use TYPO3Fluid\Fluid\Core\Component\ComponentDefinition;
use TYPO3Fluid\Fluid\Core\ViewHelper\ArgumentDefinition;

use function array_key_exists;

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

            $inputs = $hasValue && is_array($values[$argumentName]) ? $values[$argumentName] : [];
            foreach ($transformer->arguments as $inputName => $definition) {
                if (!InputPath::has($inputs, $inputName)) {
                    if ($definition->isRequired()) {
                        throw new InvalidArgumentException('Missing transformer input "' . $argumentName . '.' . $inputName . '".', 1768901557);
                    }

                    continue;
                }

                InputPath::set($inputs, $inputName, InputNormalizer::normalize($definition->getType(), InputPath::get($inputs, $inputName)));
            }

            $values[$argumentName] = $transformer->execute($inputs);
        }

        return $values;
    }

    private function hasRequiredInput(Transformer $transformer): bool
    {
        return array_any($transformer->arguments, fn(ArgumentDefinition $argument): bool => $argument->isRequired());
    }
}
