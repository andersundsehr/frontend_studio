<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Control\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class TypeControl
{
    public const string TAG_NAME = 'frontend_studio.control';

    public function __construct(public string $id, public string $type = '*', public int $priority = 0)
    {
    }
}
