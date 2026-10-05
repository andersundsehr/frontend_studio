const frontendStudioModuleName = 'admin_frontendstudio';

// The navigation tree survives content-frame navigation between variants.
export default class ComponentFileWatcher {
  constructor() {
    this.source = null;
    this.uri = '';
    this.active = false;
    this.suspended = false;
    this.pendingActions = new Set();
    this.abortController = new AbortController();
    const options = { signal: this.abortController.signal };
    const moduleChanged = (event) => this.updateModule(event.detail);
    top.document.addEventListener('typo3-module-load', moduleChanged, options);
    top.document.addEventListener('typo3-module-loaded', moduleChanged, options);
    top.document.addEventListener('frontend-studio:component-file-action-started', (event) => {
      const identifier = event.detail?.variantIdentifier || event.detail?.identifier;
      if (typeof identifier === 'string' && identifier !== '') {
        this.pendingActions.add(identifier);
      }
    }, options);
    top.document.addEventListener('frontend-studio:component-file-action-cancelled', (event) => {
      const identifier = event.detail?.variantIdentifier || event.detail?.identifier;
      this.pendingActions.delete(identifier);
    }, options);
    top.addEventListener('pagehide', (event) => {
      this.suspended = true;
      this.disconnect();
      if (!event.persisted) {
        this.destroy();
      }
    }, options);
    top.addEventListener('pageshow', (event) => {
      if (event.persisted) {
        this.suspended = false;
        this.connect();
      }
    }, options);
    this.onFilesChanged = (event) => this.handleFilesChanged(event);
  }

  updateModule(detail) {
    if (!detail?.module) {
      return;
    }
    this.active = detail.module === frontendStudioModuleName;
    if (!this.active) {
      this.uri = '';
      this.pendingActions.clear();
      this.disconnect();
      return;
    }
    if (typeof detail.componentChangeStreamUri === 'string' && detail.componentChangeStreamUri !== this.uri) {
      this.disconnect();
      this.uri = detail.componentChangeStreamUri;
    }
    this.connect();
  }

  connect() {
    if (this.source !== null || !this.active || this.suspended || this.uri === ''
      || this.abortController.signal.aborted || typeof EventSource === 'undefined') {
      return;
    }
    this.source = new EventSource(this.uri);
    this.source.addEventListener('component-files-changed', this.onFilesChanged);
  }

  disconnect() {
    this.source?.removeEventListener('component-files-changed', this.onFilesChanged);
    this.source?.close();
    this.source = null;
  }

  handleFilesChanged(event) {
    let payload;
    try {
      payload = JSON.parse(event.data);
    } catch {
      return;
    }
    if (!Array.isArray(payload?.componentIdentifiers)) {
      return;
    }
    const ownActionIdentifiers = Array.from(this.pendingActions);
    this.pendingActions.clear();
    top.document.dispatchEvent(new CustomEvent('frontend-studio:component-files-changed', {
      detail: { componentIdentifiers: payload.componentIdentifiers, ownAction: ownActionIdentifiers.length > 0, ownActionIdentifiers },
    }));
  }

  destroy() {
    this.disconnect();
    this.abortController.abort();
  }
}
