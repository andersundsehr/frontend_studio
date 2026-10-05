<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Transformer;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;
use Symfony\Component\Filesystem\Path;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3Fluid\Fluid\Core\Component\ComponentDefinition;
use TYPO3Fluid\Fluid\Core\Component\ComponentDefinitionProviderInterface;
use TYPO3Fluid\Fluid\Core\Component\ComponentTemplateResolverInterface;

use function array_keys;
use function class_exists;
use function enum_exists;
use function explode;
use function file_exists;
use function in_array;
use function interface_exists;
use function preg_replace;
use function str_ends_with;

final readonly class TransformersFactory
{
    public const array DEFAULT_SUPPORTED_TYPES = [
        'bool',
        'boolean',
        'int',
        'integer',
        'float',
        'double',
        'string',
        'array',
        'iterable',
        'mixed',
        DateTime::class,
        DateTimeImmutable::class,
        DateTimeInterface::class,
    ];

    public function __construct(
        private TypeTransformers $typeTransformers,
        private TransformerFactory $transformerFactory,
    ) {
    }

    public function get(
        ComponentTemplateResolverInterface&ComponentDefinitionProviderInterface $collection,
        string $componentName,
    ): Transformers {
        $templateName = $collection->resolveTemplateName($componentName);
        $fileName = $collection->getTemplatePaths()->resolveTemplateFileForControllerAndActionAndFormat('Default', $templateName);
        $pdaFileName = preg_replace('/(\.fluid)?\.html$/', '.transformer.php', (string)$fileName) ?:
            throw new RuntimeException(
                'Could not resolve the transformer file for the component "' . $componentName . '"',
                5992417357,
            );

        $argumentTransformers = $this->loadArgumentTransformer($pdaFileName, $componentName);

        $argumentDefinitions = $collection->getComponentDefinition($componentName)->getArgumentDefinitions();
        foreach (array_keys($argumentTransformers->arguments) as $name) {
            if (!isset($argumentDefinitions[$name])) {
                throw new RuntimeException(
                    'The transformer for argument "' . $name . '" is not present in the component. remove it from ' . $pdaFileName . ' or add it to the component.',
                    1153552856
                );
            }
        }

        $transformers = [];

        foreach ($argumentDefinitions as $argumentName => $argumentDefinition) {
            if (isset($argumentTransformers->arguments[$argumentName])) {
                $transformers[$argumentName] = $this->transformerFactory->fromCallable(
                    $argumentTransformers->arguments[$argumentName],
                    str_replace(Environment::getProjectPath() . '/', '', $pdaFileName) . ':' . $argumentName,
                );
                continue;
            }

            $type = $argumentDefinition->getType();
            if ($this->isDefaultSupportedType($type)) {
                continue;
            }

            if (enum_exists($type)) {
                continue;
            }

            if (!$this->typeTransformers->has($type)) {
                throw new MissingTransformerException($argumentName, $type, Path::makeRelative($pdaFileName, Environment::getProjectPath()));
            }

            $transformers[$argumentName] = $this->typeTransformers->get($type);
        }

        $result = new Transformers(arguments: $transformers, fromFile: $pdaFileName);

        $this->validateReturnType($collection->getComponentDefinition($componentName), $result);

        return $result;
    }

    public function validateReturnType(ComponentDefinition $componentDefinition, Transformers $transformers): void
    {
        $argumentDefinitions = $componentDefinition->getArgumentDefinitions();
        foreach (array_keys($transformers->arguments) as $argumentName) {
            $resultType = $transformers->arguments[$argumentName]->returnType;
            $targetType = $argumentDefinitions[$argumentName]->getType();
            if ($targetType === 'mixed') {
                // If the target type is mixed, we don't need to validate the result type
                continue;
            }

            if (!$this->isTypeAssignable($resultType, $targetType)) {
                throw new RuntimeException(
                    '🥺🙏 please report this!!! https://github.com/andersundsehr/storybook/issues The transformer for argument "' . $argumentName . '" returns a value of type "' . $resultType . '" but the component expects a value of type "' . $targetType . '". ' .
                        'Please adjust the transformer or the component definition.',
                    4128088840
                );
            }
        }
    }

    private function isTypeAssignable(string $resultType, string $targetType): bool
    {
        foreach ($this->splitUnionType($resultType) as $resultTypePart) {
            $isCompatible = array_any($this->splitUnionType($targetType), fn(string $targetTypePart): bool => $this->isSingleTypeAssignable($resultTypePart, $targetTypePart));
            if (!$isCompatible) {
                return false;
            }
        }

        return true;
    }

    private function isDefaultSupportedType(string $type): bool
    {
        return array_all($this->splitUnionType(ltrim($type, '?')), fn(string $typePart): bool => in_array($typePart, self::DEFAULT_SUPPORTED_TYPES, true) || $typePart === 'null');
    }

    /**
     * @return list<string>
     */
    private function splitUnionType(string $type): array
    {
        return explode('|', $type);
    }

    private function isSingleTypeAssignable(string $resultType, string $targetType): bool
    {
        if ($resultType === $targetType) {
            return true;
        }

        if (str_ends_with($targetType, '[]') && $resultType === 'array') {
            return true;
        }

        $targetIsClass = class_exists($targetType) || interface_exists($targetType);
        $resultIsClass = class_exists($resultType) || interface_exists($resultType);

        return $targetIsClass && $resultIsClass && is_a($resultType, $targetType, true);
    }

    private function loadArgumentTransformer(string $pdaFileName, string $componentName): ArgumentTransformers
    {
        if (!file_exists($pdaFileName)) {
            return new ArgumentTransformers();
        }

        $argumentTransformers = require $pdaFileName;
        if (!$argumentTransformers instanceof ArgumentTransformers) {
            throw new RuntimeException(
                'The transformer file ' . $pdaFileName . ' for the component "' . $componentName . '" did not return an instance of ' . ArgumentTransformers::class,
                3130845333,
            );
        }

        return $argumentTransformers;
    }
}
