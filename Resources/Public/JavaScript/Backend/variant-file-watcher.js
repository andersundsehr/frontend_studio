import VariantFeature from '@andersundsehr/frontend-studio/backend/variant-lifecycle.js';

export default class VariantFileWatcher extends VariantFeature {

  constructor(root, view) {
    super(root, view);
    this.suspended = false;
    this.listen(view, 'suspend', () => { this.suspended = true; });
    this.listen(view, 'resume', () => { this.suspended = false; });
    this.listen(top.document, 'frontend-studio:component-files-changed', (event) => this.handleComponentFilesChanged(event));
  }

  handleComponentFilesChanged(event) {
    if (this.suspended || !this.isCurrentComponentAffected(event)) {
      return;
    }
    const ownActionIdentifiers = event.detail.ownActionIdentifiers || [];
    if (ownActionIdentifiers.some((identifier) => identifier === this.view.componentIdentifier || identifier.startsWith(`${this.view.componentIdentifier}:`))
      || this.view.ignoreNextComponentFilesChanged) {
      this.view.ignoreNextComponentFilesChanged = false;
      return;
    }
    this.view.changed('files');

    if (!this.view.hasUnsavedChanges && !this.view.documentationDirty) {
      window.location.reload();
    }
  }

  isCurrentComponentAffected(event) {
    if (this.view.componentIdentifier === '') {
      return false;
    }

    const payload = event.detail;
    if (!Array.isArray(payload?.componentIdentifiers)) {
      return false;
    }

    return payload.componentIdentifiers.includes(this.view.componentIdentifier);
  }
}
