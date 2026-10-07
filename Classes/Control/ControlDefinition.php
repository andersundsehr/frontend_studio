<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Control;

use InvalidArgumentException;

final readonly class ControlDefinition
{
    public string $optionsJson;

    /** @param array<string, mixed> $options */
    public function __construct(public string $template, public string $module, public array $options = [])
    {
        if (!str_starts_with($template, 'EXT:') || str_contains($template, '..') || !str_starts_with($module, '@')) {
            throw new InvalidArgumentException('Controls require an extension template and a registered import-map module.', 1791200010);
        }

        $this->optionsJson = json_encode($options, JSON_THROW_ON_ERROR);
    }
}
