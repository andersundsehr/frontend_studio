const frontendStudioModuleName = 'admin_frontendstudio';

// The navigation tree survives content-frame navigation between variants.
export default class ComponentFileWatcher {
  constructor() {
    this.source = null;
    this.uri = '';
    this.active = false;
    this.suspended = false;
    this.pendingVariantActions = new Map();
    this.pendingTreeActions = new Set();
    this.abortController = new AbortController();
    const options = { signal: this.abortController.signal };
    const moduleChanged = (event) => this.updateModule(event.detail);
    top.document.addEventListener('typo3-module-load', moduleChanged, options);
    top.document.addEventListener('typo3-module-loaded', moduleChanged, options);
    ['started', 'cancelled'].forEach((type) => {
      top.document.addEventListener(`frontend-studio:component-file-action-${type}`, (event) => {
        const identifier = event.detail?.variantIdentifier || event.detail?.identifier;
        if (typeof identifier !== 'string' || identifier === '') {
          return;
        }
        const isVariantAction = event.detail.variantIdentifier !== undefined;
        const actions = isVariantAction ? this.pendingVariantActions : this.pendingTreeActions;
        const key = isVariantAction ? event.detail.scope || identifier : identifier;
        if (type === 'started') {
          if (isVariantAction) {
            actions.set(key, identifier);
          } else {
            actions.add(key);
          }
        } else {
          actions.delete(key);
        }
      }, options);
    });
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
      this.pendingVariantActions.clear();
      this.pendingTreeActions.clear();
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
    const documentationIdentifiers = payload.documentationComponentIdentifiers;
    if (Array.isArray(documentationIdentifiers) && documentationIdentifiers.length > 0) {
      top.document.dispatchEvent(new CustomEvent('frontend-studio:component-documentation-changed', {
        detail: { componentIdentifiers: documentationIdentifiers },
      }));
      if (payload.componentIdentifiers.length === 0) return;
    }
    const matches = (identifier) => payload.componentIdentifiers.some((component) => (
      identifier === component || identifier.startsWith(`${component}:`)
    ));
    const ownActionIdentifiers = Array.from(this.pendingTreeActions).filter(matches);
    let ownAction = ownActionIdentifiers.length > 0;
    ownActionIdentifiers.forEach((identifier) => this.pendingTreeActions.delete(identifier));
    this.pendingVariantActions.forEach((identifier, key) => {
      if (matches(identifier)) {
        ownAction = true;
        this.pendingVariantActions.delete(key);
      }
    });
    top.document.dispatchEvent(new CustomEvent('frontend-studio:component-files-changed', {
      detail: { componentIdentifiers: payload.componentIdentifiers, ownAction, ownActionIdentifiers },
    }));
  }

  destroy() {
    this.disconnect();
    this.abortController.abort();
  }
}
