import Notification from '@typo3/backend/notification.js';
import VariantPanel from '@andersundsehr/frontend-studio/backend/variant-panel.js';

export default class VariantUsage extends VariantPanel {
  constructor(root, view) {
    super(root, view, 'fluid-usage');
    this.blocks = Array.from(root.querySelectorAll('[data-frontend-studio-fluid-usage-block]'));
    root.querySelectorAll('[data-frontend-studio-copy-fluid-usage]').forEach((button) => {
      this.listen(button, 'click', () => this.copy(button.dataset.frontendStudioCopyFluidUsage));
    });
  }

  render(source) {
    const snippets = JSON.parse(source);
    this.blocks.forEach((block) => {
      const snippet = snippets[block.dataset.frontendStudioFluidUsageBlock] || '';
      block.querySelector('[data-frontend-studio-fluid-usage-code]').innerHTML = snippet;
      block.hidden = snippet === '';
    });
  }

  setStatus(message, isError = false) {
    if (isError) {
      Notification.error('Fluid usage could not be loaded', message);
    }
  }

  async copy(syntax) {
    const snippet = this.root.querySelector(`[data-frontend-studio-fluid-usage-code="${syntax}"]`)?.textContent || '';
    if (snippet === '') {
      return;
    }
    try {
      await navigator.clipboard.writeText(snippet);
      if (!this.destroyed) {
        Notification.success('Fluid usage copied', snippet);
      }
    } catch (error) {
      if (!this.destroyed) {
        Notification.error('Copy failed', error?.message || 'The Fluid usage snippet could not be copied.');
      }
    }
  }
}
