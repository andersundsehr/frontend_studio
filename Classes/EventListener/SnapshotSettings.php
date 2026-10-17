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

    /**
     * Expose snapshot settings in the backend shell, where the component tree
     * and its SnapshotTests instance run. Settings emitted inside the module
     * iframe do not reach this navigation tree.
     *
     * AfterBackendPageRenderEvent runs before PageRenderer builds the response,
     * so TYPO3.settings.frontendStudio contains the GUI testing toggle and
     * Production write policy before the tree initializes. These flags hide
     * disabled GUI test actions and disable snapshot updates in Production and
     * its subcontexts.
     * SnapshotController and ComponentWritePolicy enforce the restrictions on
     * the server as well.
     */
    #[AsEventListener(event: AfterBackendPageRenderEvent::class)]
    public function __invoke(): void
    {
        $this->pageRenderer->addInlineSetting('frontendStudio', 'snapshotTestingEnabled', (bool)$this->extensionConfiguration->get('frontend_studio', 'enableGuiTesting'));
        $this->pageRenderer->addInlineSetting('frontendStudio', 'snapshotReadOnly', $this->writePolicy->isReadOnly());
    }
}
