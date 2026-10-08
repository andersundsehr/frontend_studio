<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\Control\RichText;

use Andersundsehr\FrontendStudio\Control\Attribute\TypeControl;
use Andersundsehr\FrontendStudio\Control\ControlContext;
use Andersundsehr\FrontendStudio\Control\ControlDefinition;
use Andersundsehr\FrontendStudio\Transformer\Attribute\TypeTransformer;
use Stringable;
use TYPO3Fluid\Fluid\Core\Parser\UnsafeHTML;

final readonly class RichTextProvider
{
    #[TypeTransformer(priority: 200)]
    public function stringable(string $string): Stringable|string
    {
        return $string === '' ? '' : new RichTextValue($string);
    }

    #[TypeTransformer(priority: 200)]
    public function unsafeHtml(string $string): UnsafeHTML|string
    {
        return $string === '' ? '' : new RichTextValue($string);
    }

    #[TypeControl('frontend-studio.rich-text', 'string|Stringable')]
    #[TypeControl('frontend-studio.rich-text-unsafe', 'string|TYPO3Fluid\Fluid\Core\Parser\UnsafeHTML')]
    public function control(ControlContext $context): ?ControlDefinition
    {
        if ($context->input?->getName() !== 'string' || !in_array($context->transformerSource, [self::class . '::stringable', self::class . '::unsafeHtml'], true)) {
            return null;
        }

        return new ControlDefinition(
            'EXT:frontend_studio/Resources/Private/Controls/RichText.fluid.html',
            '@andersundsehr/frontend-studio/backend/rich-text-control.js',
        );
    }
}
