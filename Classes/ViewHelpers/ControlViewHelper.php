<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\ViewHelpers;

use Andersundsehr\FrontendStudio\Dto\ComponentVariantValueMetadata;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TYPO3Fluid\Fluid\View\TemplateView;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextFactory;
use Psr\Http\Message\ServerRequestInterface;

final class ControlViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        $this->registerArgument('value', ComponentVariantValueMetadata::class, 'The trusted server-resolved control metadata', true);
    }

    public function render(): string
    {
        $value = $this->arguments['value'];
        if (!$value instanceof ComponentVariantValueMetadata || $value->control === null || $this->renderingContext === null) {
            return '';
        }

        $request = $this->renderingContext->hasAttribute(ServerRequestInterface::class)
            ? $this->renderingContext->getAttribute(ServerRequestInterface::class) : null;
        $context = GeneralUtility::makeInstance(RenderingContextFactory::class)->create([], $request);
        $context->getTemplatePaths()->setTemplatePathAndFilename(GeneralUtility::getFileAbsFileName($value->control->template));
        $view = new TemplateView($context);
        $view->assign('variantValue', $value);
        return (string)$view->render();
    }
}
