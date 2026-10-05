import VariantFeature from '@andersundsehr/frontend-studio/backend/variant-lifecycle.js';

export default class VariantPreview extends VariantFeature {
  constructor(root, view) {
    super(root, view);
    this.iframe = root.querySelector('[data-frontend-studio-variant-frame]');
    this.initializeViewport();
    ['values', 'context', 'files'].forEach((type) => this.listen(view, type, () => this.update(type === 'files')));
    if (view.previewChanged) {
      this.update();
    }
  }

  initializeViewport() {
    this.viewport = this.root.querySelector('[data-frontend-studio-viewport]');
    if (this.viewport === null) {
      return;
    }
    this.customViewport = this.root.querySelector('[data-frontend-studio-viewport-custom]');
    this.width = this.root.querySelector('[data-frontend-studio-viewport-width]');
    this.height = this.root.querySelector('[data-frontend-studio-viewport-height]');
    this.resetViewport = this.root.querySelector('[data-frontend-studio-viewport-reset]');
    this.listen(this.viewport, 'change', () => this.updateViewport());
    [this.width, this.height].forEach((field) => {
      this.listen(field, 'input', () => this.updateViewport());
      this.listen(field, 'change', () => field.reportValidity());
    });
    this.listen(this.resetViewport, 'click', () => {
      this.viewport.value = 'responsive';
      this.updateViewport();
    });
    this.updateViewport();
  }

  updateViewport() {
    const mode = this.viewport.value;
    this.customViewport.hidden = mode !== 'custom';
    this.resetViewport.disabled = mode === 'responsive';
    if (mode === 'responsive') {
      this.iframe.style.removeProperty('width');
      this.iframe.style.removeProperty('height');
      return;
    }
    if (mode !== 'custom') {
      const preset = this.viewport.selectedOptions[0];
      this.width.value = preset.dataset.width;
      this.height.value = preset.dataset.height;
    }
    if (this.width.checkValidity() && this.height.checkValidity()) {
      this.iframe.style.width = `${this.width.valueAsNumber}px`;
      this.iframe.style.height = `${this.height.valueAsNumber}px`;
    }
  }

  update(force = false) {
    const url = this.view.buildPreviewUrl()?.toString() || 'about:blank';
    if (force || this.iframe.src !== url) {
      this.iframe.src = url;
    }
  }
}
