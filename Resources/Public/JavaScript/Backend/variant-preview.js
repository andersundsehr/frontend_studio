import VariantFeature from '@andersundsehr/frontend-studio/backend/variant-lifecycle.js';

export default class VariantPreview extends VariantFeature {
  constructor(root, view) {
    super(root, view);
    this.iframe = root.querySelector('[data-frontend-studio-variant-frame]');
    ['values', 'context', 'files'].forEach((type) => this.listen(view, type, () => this.update(type === 'files')));
    if (view.previewChanged) {
      this.update();
    }
  }

  update(force = false) {
    const url = this.view.buildPreviewUrl()?.toString() || 'about:blank';
    if (force || this.iframe.src !== url) {
      this.iframe.src = url;
    }
  }
}
