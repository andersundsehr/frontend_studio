import VariantFeature from '@andersundsehr/frontend-studio/backend/variant-lifecycle.js';

// Eagerly mounted: the error badge must stay current even while another tab is active.
export default class VariantTemplate extends VariantFeature {
  constructor(root, view) {
    super(root, view);
    this.source = root.querySelector('[data-frontend-studio-template-source]');
    this.status = root.querySelector('[data-frontend-studio-template-status]');
    this.badge = root.querySelector('[data-frontend-studio-template-badge]');
    this.count = root.querySelector('[data-frontend-studio-template-count]');
    this.requestId = 0;
    this.request = null;
    ['files', 'saved', 'resume'].forEach((type) => this.listen(view, type, () => this.refresh()));
    this.listen(view, 'suspend', () => this.invalidate());
  }

  invalidate() {
    this.requestId += 1;
    this.request?.abort();
  }

  async refresh() {
    const endpoint = TYPO3.settings.ajaxUrls.frontend_studio_template_analysis;
    if (!endpoint || this.destroyed) {
      return;
    }
    this.invalidate();
    const requestId = this.requestId;
    const identifier = this.view.variantIdentifier;
    this.request = new AbortController();
    const url = new URL(endpoint, window.location.href);
    url.searchParams.set('componentVariant', identifier);
    try {
      const response = await fetch(url, { signal: this.request.signal, credentials: 'same-origin' });
      if (!response.ok) {
        throw new Error(`Fluid analysis could not be refreshed (${response.status}).`);
      }
      const result = await response.json();
      if (this.destroyed || requestId !== this.requestId || identifier !== this.view.variantIdentifier) {
        return;
      }
      this.source.innerHTML = result.source;
      this.status.textContent = result.status;
      this.status.hidden = result.status === '';
      this.count.textContent = String(result.errorCount);
      this.badge.hidden = result.errorCount === 0;
    } catch (error) {
      if (this.destroyed || requestId !== this.requestId || identifier !== this.view.variantIdentifier) {
        return;
      }
      this.status.textContent = error?.message || 'Fluid analysis could not be refreshed.';
      this.status.hidden = false;
      this.count.textContent = '1';
      this.badge.hidden = false;
    }
  }

  destroy() {
    this.invalidate();
    super.destroy();
  }
}
