import VariantFeature from '@andersundsehr/frontend-studio/backend/variant-lifecycle.js';

export default class VariantPreview extends VariantFeature {
  constructor(root, view) {
    super(root, view);
    this.iframe = root.querySelector('[data-frontend-studio-variant-frame]');
    ['values', 'context', 'files'].forEach((type) => this.listen(view, type, () => this.update()));
    this.update();
  }

  update() {
    const url = this.view.buildPreviewUrl();
    this.iframe.src = url?.toString() || 'about:blank';
  }
}
