<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Tests\Functional\Fixtures\Extensions\preview_site_set;

use Andersundsehr\FrontendStudio\Control\Attribute\TypeControl;
use Andersundsehr\FrontendStudio\Control\ControlContext;
use Andersundsehr\FrontendStudio\Control\ControlDefinition;

final class ControlProvider
{
    #[TypeControl('test.custom')]
    public function custom(ControlContext $context): ?ControlDefinition
    {
        return $context->input?->getName() === 'text'
            ? new ControlDefinition('EXT:frontend_studio_preview_test/Resources/Private/Control.fluid.html', '@frontend-studio-test/control.js', ['suffix' => '!'])
            : null;
    }
}
