<?php

declare(strict_types=1);

namespace Andersundsehr\FrontendStudio\EventListener;

use Andersundsehr\FrontendStudio\Service\ComponentWritePolicy;
use TYPO3\CMS\Backend\Controller\Event\AfterBackendPageRenderEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Page\PageRenderer;

final readonly class SnapshotSettings
{
    public function __construct(
        private PageRenderer $pageRenderer,
        private ExtensionConfiguration $extensionConfiguration,
        private ComponentWritePolicy $writePolicy,
    ) {
    }

    #[AsEventListener(event: AfterBackendPageRenderEvent::class)]
    public function __invoke(): void
    {
        $this->pageRenderer->addInlineSetting('frontendStudio', 'snapshotTestingEnabled', (bool)$this->extensionConfiguration->get('frontend_studio', 'enableGuiTesting'));
        $this->pageRenderer->addInlineSetting('frontendStudio', 'snapshotReadOnly', $this->writePolicy->isReadOnly());
    }
}
