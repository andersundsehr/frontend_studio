<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Transformer;

use RuntimeException;

final class MissingTransformerException extends RuntimeException
{
    /** @param array<string, string> $missingArguments */
    public function __construct(
        public readonly string $argumentName,
        public readonly string $argumentType,
        public readonly string $transformerFile,
        public readonly array $missingArguments = [],
    ) {
        parent::__construct(
            'No transformer is available for argument "' . $argumentName . '" of type "' . $argumentType . '". '
                . 'Add an ArgumentTransformers entry for this argument in "' . $transformerFile . '" '
                . 'or register a service method with #[TypeTransformer] for this type. Reload the component after adding the transformer.',
            6790927084,
        );
    }
}
