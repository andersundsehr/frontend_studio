<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Control;

use Closure;
use InvalidArgumentException;

final class TypeControls
{
    /** @var array<string, array{type: string, priority: int, provider: Closure(ControlContext): ?ControlDefinition}> */
    private array $providers = [];

    public function addControl(object $handler, string $method, string $id, string $type, int $priority): void
    {
        if ($id === '' || isset($this->providers[$id]) || !is_callable([$handler, $method])) {
            throw new InvalidArgumentException('Duplicate control ID or invalid provider: ' . $id, 1791200011);
        }

        $this->providers[$id] = ['type' => $this->normalizeType($type), 'priority' => $priority, 'provider' => $handler->{$method}(...)];
    }

    public function resolve(ControlContext $context, ?string $override = null): ?ControlDefinition
    {
        if ($override !== null) {
            $provider = $this->providers[$override] ?? throw new InvalidArgumentException('Unknown control: ' . $override, 1791200012);
            return ($provider['provider'])($context) ?? throw new InvalidArgumentException('Control declined explicit selection: ' . $override, 1791200013);
        }

        $type = $this->normalizeType($context->argument->type);
        $providers = array_filter($this->providers, static fn(array $provider): bool => $provider['type'] === $type || $provider['type'] === '*');
        // Exact types precede wildcard providers; priority then stable ID breaks ties.
        uksort($providers, static fn(string $a, string $b): int =>
            ($providers[$b]['type'] === $type) <=> ($providers[$a]['type'] === $type)
            ?: $providers[$b]['priority'] <=> $providers[$a]['priority'] ?: strcmp($a, $b));
        foreach ($providers as $provider) {
            $definition = ($provider['provider'])($context);
            if ($definition !== null) {
                return $definition;
            }
        }

        return null;
    }

    private function normalizeType(string $type): string
    {
        $type = trim($type);
        if (str_starts_with($type, '?')) {
            $type = substr($type, 1) . '|null';
        }

        $parts = array_unique(array_map(static fn(string $part): string => ltrim(trim($part), '\\'), explode('|', $type)));
        sort($parts);
        return implode('|', $parts);
    }
}
