<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Control;

use Andersundsehr\FrontendStudio\Dto\ComponentArgumentMetadata;
use TYPO3Fluid\Fluid\Core\ViewHelper\ArgumentDefinition;

final readonly class ControlContext
{
    public function __construct(
        public ComponentArgumentMetadata $argument,
        public mixed $value,
        public bool $isFixtureValue,
        public ?ArgumentDefinition $input = null,
        public ?string $transformerSource = null,
    ) {
    }
}
