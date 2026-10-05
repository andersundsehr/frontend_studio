import VariantFeature from '@andersundsehr/frontend-studio/backend/variant-lifecycle.js';

export function previewErrorMessage(source, fallback) {
  try {
    const payload = JSON.parse(source);
    if (typeof payload.message === 'string') {
      return payload.message;
    }
  } catch {
    // Preview errors can also be returned as HTML.
  }
  const document = new DOMParser().parseFromString(source, 'text/html');
  const message = Array.from(document.body.children, (element) => element.textContent.trim()).join(' ').trim()
    || document.body.textContent.trim();
  return message || fallback;
}

export default class VariantPanel extends VariantFeature {
  constructor(root, view, format) {
    super(root, view);
    this.format = format;
    this.requestId = 0;
    this.previewUrl = '';
    this.timeout = null;
    this.request = null;
    this.listen(view, 'suspend', () => this.invalidate());
  }

  invalidate() {
    this.requestId += 1;
    this.request?.abort();
    window.clearTimeout(this.timeout);
    this.previewUrl = '';
  }

  schedule(delay = 0) {
    window.clearTimeout(this.timeout);
    this.timeout = window.setTimeout(() => this.refresh(), delay);
  }

  async refresh() {
    const url = this.view.buildPreviewUrl(this.format)?.toString();
    if (this.destroyed || url === undefined || url === this.previewUrl) {
      return;
    }
    this.invalidate();
    this.previewUrl = url;
    const requestId = this.requestId;
    const revision = this.view.revision;
    this.request = new AbortController();
    this.setStatus('Loading...');
    try {
      const response = await fetch(url, {
        signal: this.request.signal,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      const source = await response.text();
      if (this.destroyed || requestId !== this.requestId || revision !== this.view.revision) {
        return;
      }
      if (!response.ok) {
        throw new Error(previewErrorMessage(source, `The preview request failed with status ${response.status}.`));
      }
      this.render(source);
      this.setStatus('');
    } catch (error) {
      if (this.destroyed || requestId !== this.requestId || revision !== this.view.revision) {
        return;
      }
      this.previewUrl = '';
      this.setStatus(error?.message || 'The preview could not be loaded.', true);
    }
  }

  destroy() {
    this.invalidate();
    super.destroy();
  }
}
