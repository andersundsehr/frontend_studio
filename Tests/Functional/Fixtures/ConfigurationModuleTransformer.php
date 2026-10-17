<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Functional\Fixtures;

use Andersundsehr\FrontendStudio\Transformer\Defaults\TypolinkTargetEnum;

final class ConfigurationModuleTransformer
{
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function transform(
        string $value,
        bool $enabled = true,
        bool $disabled = false,
        mixed $nullable = null,
        string $empty = '',
        int $count = 5,
        float $ratio = 1.5,
        TypolinkTargetEnum $target = TypolinkTargetEnum::none,
        array $options = [
            'enabled' => true,
            'disabled' => false,
            'nullable' => null,
            'empty' => '',
            'nested' => [
                'values' => [true, false, null, '', 0, 1.5, 'value', TypolinkTargetEnum::_blank, TypolinkTargetEnum::none],
                'emptyArray' => [],
            ],
        ],
    ): array {
        return [
            'value' => $value,
            'enabled' => $enabled,
            'disabled' => $disabled,
            'nullable' => $nullable,
            'empty' => $empty,
            'count' => $count,
            'ratio' => $ratio,
            'target' => $target,
            'options' => $options,
        ];
    }
}
