<?php

declare(strict_types=1);

use Andersundsehr\FrontendStudio\Dto\ComponentVariantValueMetadata;
use Andersundsehr\FrontendStudio\Transformer\ArgumentTransformers;
use Andersundsehr\FrontendStudio\ValueFormatter;

return new ArgumentTransformers(
    variantValue: static fn(
        string $name,
        string $type,
        mixed $value,
        string $description = '',
        bool $required = false,
        bool $isMultiline = false,
        bool $isDate = false,
        array $options = [],
    ): ComponentVariantValueMetadata => new ComponentVariantValueMetadata(
        $name,
        $type,
        $description,
        ValueFormatter::format($value),
        $value,
        $isMultiline,
        true,
        $required,
        null,
        $name,
        $isDate,
        $options,
    ),
);
