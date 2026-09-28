<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Transformer;

use Andersundsehr\FrontendStudio\Transformer\TransformerFactory;
use RuntimeException;

use function count;
use function explode;
use function is_a;
use function max;
use function sort;
use function str_contains;
use function trim;

use const PHP_INT_MIN;

/**
 * @phpstan-type TransformerConfigArray array{
 *      handler: class-string<object>,
 *      method: string,
 *      returnType: string,
 *      priority: int
 *  }
 */
final class TypeTransformers
{
    /**
     * @var array<string, list<TransformerConfigArray>>
     */
    private array $configuration = [];

    /** @var array<string, Transformer> */
    private array $handlers = [];

    /**
     * @var array<string, int>
     */
    private array $priorities = [];

    public function __construct(private readonly TransformerFactory $transformerFactory)
    {
    }

    /**
     * called from Symfony DI configuration
     */
    public function addTransformer(object $handler, string $method, string $returnType, int $priority): void
    {
        if (!method_exists($handler, $method)) {
            throw new RuntimeException(
                'The method "' . $method . '" does not exist on the handler class "' . $handler::class . '".',
                1988452467
            );
        }

        $returnType = $this->normalizeType($returnType);

        $this->configuration[$returnType] ??= [];
        $this->configuration[$returnType][] = [
            'handler' => $handler::class,
            'method' => $method,
            'returnType' => $returnType,
            'priority' => $priority,
        ];

        if (($this->priorities[$returnType] ?? PHP_INT_MIN) > $priority) {
            // If a transformer with a higher priority already exists, do not add this one
            return;
        }

        $this->handlers[$returnType] = $this->transformerFactory->fromCallable(
            $handler->{$method}(...),
            $handler::class . '::' . $method
        );
        $this->priorities[$returnType] = $priority;
    }

    public function has(string $returnType): bool
    {
        return (bool)$this->getInternal($returnType);
    }

    public function get(string $returnType): Transformer
    {
        return $this->getInternal($returnType) ?? throw new RuntimeException(
            'No transformer found for return type "' . $returnType . '".',
            1988452468
        );
    }

    private function getInternal(string $returnType): ?Transformer
    {
        $returnType = $this->normalizeType($returnType);

        if (isset($this->handlers[$returnType])) {
            return $this->handlers[$returnType];
        }

        $maxPriority = PHP_INT_MIN;
        $maxSpecificity = -1.0;
        $matched = null;
        foreach ($this->handlers as $type => $transformer) {
            // Cached lookups are not registrations and must not affect match specificity.
            if (!isset($this->configuration[$type])) {
                continue;
            }

            $specificity = $this->getMatchSpecificity($type, $returnType);
            if ($specificity === null) {
                continue;
            }

            $priority = $this->priorities[$type] ?? throw new RuntimeException('No priority found for transformer of type "' . $type . '".', 6799603216);
            if ($priority < $maxPriority || ($priority === $maxPriority && $specificity < $maxSpecificity)) {
                continue;
            }

            $maxPriority = $priority;
            $maxSpecificity = $specificity;
            $matched = $transformer;
        }

        if ($matched) {
            // cache the result:
            $this->handlers[$returnType] = $matched;
            $this->priorities[$returnType] = $maxPriority;
        }

        return $matched;
    }

    private function getMatchSpecificity(string $registeredType, string $requestedType): ?float
    {
        $registeredTypeParts = $this->splitUnionType($registeredType);
        $requestedTypeParts = $this->splitUnionType($requestedType);
        $exactMatches = 0;

        foreach ($registeredTypeParts as $registeredTypePart) {
            $isAssignable = false;
            foreach ($requestedTypeParts as $requestedTypePart) {
                if ($this->normalizeType($registeredTypePart) === $this->normalizeType($requestedTypePart)) {
                    $exactMatches++;
                    $isAssignable = true;
                    break;
                }

                $isAssignable = $isAssignable || is_a($registeredTypePart, $requestedTypePart, true);
            }

            if (!$isAssignable) {
                return null;
            }
        }

        return $exactMatches / max(count($registeredTypeParts), count($requestedTypeParts));
    }

    /**
     * @return list<string>
     */
    private function splitUnionType(string $type): array
    {
        return explode('|', $type);
    }

    private function normalizeType(string $type): string
    {
        if (!str_contains($type, '|')) {
            return trim($type);
        }

        $parts = array_map(trim(...), $this->splitUnionType($type));
        sort($parts);

        return implode('|', $parts);
    }

    /**
     * @return array{raw: array<string, list<TransformerConfigArray>>, used: array<string, Transformer>}
     */
    public function getConfiguration(): array
    {
        return [
            'raw' => $this->configuration,
            'used' => $this->handlers,
        ];
    }
}
