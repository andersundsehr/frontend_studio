import VariantFeature from '@andersundsehr/frontend-studio/backend/variant-lifecycle.js';

export default class VariantFileWatcher extends VariantFeature {

  constructor(root, view) {
    super(root, view);
    this.componentIdentifier = this.getComponentIdentifierFromVariantIdentifier(view.variantIdentifier);
    this.source = null;
    this.onFilesChanged = (event) => this.handleComponentFilesChanged(event);
    this.connect();
    this.listen(view, 'suspend', () => this.disconnect());
    this.listen(view, 'resume', () => this.connect());
  }

  connect() {
    if (this.source !== null || this.destroyed) {
      return;
    }
    this.source = new EventSource(this.root.dataset.componentChangeStreamUri);
    this.listen(this.source, 'component-files-changed', this.onFilesChanged);
  }

  disconnect() {
    this.source?.removeEventListener('component-files-changed', this.onFilesChanged);
    this.source?.close();
    this.source = null;
  }

  destroy() {
    this.disconnect();
    super.destroy();
  }

  handleComponentFilesChanged(event) {
    const isCurrentComponentAffected = this.isCurrentComponentAffected(event);
    if (isCurrentComponentAffected && this.view.ignoreNextComponentFilesChanged) {
      this.view.ignoreNextComponentFilesChanged = false;
      return;
    }

    top.document.dispatchEvent(new CustomEvent('frontend-studio:component-files-changed', {
      detail: {
        variantIdentifier: this.view.variantIdentifier,
      },
    }));

    if (!isCurrentComponentAffected) {
      return;
    }

    this.view.changed('files');

    if (!this.view.hasUnsavedChanges) {
      window.location.reload();
    }
  }

  isCurrentComponentAffected(event) {
    if (this.componentIdentifier === '') {
      return false;
    }

    const payload = this.parseComponentFilesChangedPayload(event);
    if (!Array.isArray(payload.componentIdentifiers)) {
      return false;
    }

    return payload.componentIdentifiers.includes(this.componentIdentifier);
  }

  parseComponentFilesChangedPayload(event) {
    try {
      return JSON.parse(event?.data || '{}');
    } catch {
      return {};
    }
  }

  getComponentIdentifierFromVariantIdentifier(variantIdentifier) {
    const identifierParts = variantIdentifier.split(':');
    if (identifierParts.length < 3) {
      return '';
    }

    return identifierParts.slice(0, -1).join(':');
  }
}
