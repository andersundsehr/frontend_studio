<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Service;

use TYPO3\CMS\Core\Core\Environment;

final readonly class ComponentWritePolicy
{
    public function isReadOnly(): bool
    {
        return Environment::getContext()->isProduction();
    }

    public function assertWritable(): void
    {
        if ($this->isReadOnly()) {
            throw new ComponentWriteDeniedException('Component files are read-only in Production contexts.', 1791191400);
        }
    }
}
