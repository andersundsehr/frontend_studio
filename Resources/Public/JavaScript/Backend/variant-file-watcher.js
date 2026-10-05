import VariantFeature from '@andersundsehr/frontend-studio/backend/variant-lifecycle.js';

export default class VariantFileWatcher extends VariantFeature {

  constructor(root, view) {
    super(root, view);
    this.componentIdentifier = this.getComponentIdentifierFromVariantIdentifier(view.variantIdentifier);
    this.ignoreNextComponentFilesChanged = false;
    this.source = new EventSource(root.dataset.componentChangeStreamUri);
    this.listen(this.source, 'component-files-changed', (event) => this.handleComponentFilesChanged(event));
    this.listen(top.document, 'frontend-studio:component-file-action-started', () => {
      this.ignoreNextComponentFilesChanged = true;
    });
    this.listen(top.document, 'frontend-studio:component-file-action-cancelled', () => {
      this.ignoreNextComponentFilesChanged = false;
    });
  }

  destroy() {
    this.source.close();
    super.destroy();
  }

  handleComponentFilesChanged(event) {
    if (this.ignoreNextComponentFilesChanged) {
      this.ignoreNextComponentFilesChanged = false;
      return;
    }

    top.document.dispatchEvent(new CustomEvent('frontend-studio:component-files-changed', {
      detail: {
        variantIdentifier: this.view.variantIdentifier,
      },
    }));

    if (!this.isCurrentComponentAffected(event)) {
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
