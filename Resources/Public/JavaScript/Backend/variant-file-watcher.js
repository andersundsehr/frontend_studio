import VariantFeature from '@andersundsehr/frontend-studio/backend/variant-lifecycle.js';

export default class VariantFileWatcher extends VariantFeature {

  constructor(root, view) {
    super(root, view);
    this.componentIdentifier = this.getComponentIdentifierFromVariantIdentifier(view.variantIdentifier);
    this.suspended = false;
    this.listen(view, 'suspend', () => { this.suspended = true; });
    this.listen(view, 'resume', () => { this.suspended = false; });
    this.listen(top.document, 'frontend-studio:component-files-changed', (event) => this.handleComponentFilesChanged(event));
  }

  handleComponentFilesChanged(event) {
    if (!Array.isArray(event.detail?.componentIdentifiers)) {
      return;
    }
    const ownActionIdentifiers = event.detail.ownActionIdentifiers || [];
    if (ownActionIdentifiers.some((identifier) => identifier === this.componentIdentifier || identifier.startsWith(`${this.componentIdentifier}:`))
      || this.view.ignoreNextComponentFilesChanged) {
      this.view.ignoreNextComponentFilesChanged = false;
      return;
    }
    if (this.suspended || !this.isCurrentComponentAffected(event)) {
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

    const payload = event.detail;
    if (!Array.isArray(payload?.componentIdentifiers)) {
      return false;
    }

    return payload.componentIdentifiers.includes(this.componentIdentifier);
  }

  getComponentIdentifierFromVariantIdentifier(variantIdentifier) {
    const identifierParts = variantIdentifier.split(':');
    if (identifierParts.length < 3) {
      return '';
    }

    return identifierParts.slice(0, -1).join(':');
  }
}
