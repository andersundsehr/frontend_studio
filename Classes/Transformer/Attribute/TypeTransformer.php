<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Transformer\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class TypeTransformer
{
    public const string TAG_NAME = 'frontend_studio.transformer.type';

    public function __construct(
        /** the highest wins */
        public int $priority = 0,
    ) {
    }
}
