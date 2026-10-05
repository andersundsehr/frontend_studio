const views = new WeakMap();

export class VariantState extends EventTarget {
  constructor(root) {
    super();
    this.root = root;
    this.previewUri = root.dataset.previewUri || '';
    this.previewChanged = false;
    this.variantIdentifier = root.dataset.variantIdentifier || '';
    this.controls = null;
    this.hasUnsavedChanges = false;
    this.ignoreNextComponentFilesChanged = false;
    this.revision = 0;
    this.destroyed = false;
    this.features = new Map();
    this.abortController = new AbortController();
    // Remember file actions even before the optional watcher module has loaded.
    top.document.addEventListener('frontend-studio:component-file-action-started', (event) => {
      if (event.detail?.variantIdentifier === this.variantIdentifier) {
        this.ignoreNextComponentFilesChanged = true;
      }
    }, { signal: this.abortController.signal });
    top.document.addEventListener('frontend-studio:component-file-action-cancelled', (event) => {
      if (event.detail?.variantIdentifier === this.variantIdentifier) {
        this.ignoreNextComponentFilesChanged = false;
      }
    }, { signal: this.abortController.signal });
    window.addEventListener('pagehide', (event) => {
      if (event.persisted) {
        this.changed('suspend');
      } else {
        this.destroy();
      }
    }, { signal: this.abortController.signal });
    window.addEventListener('pageshow', (event) => {
      if (event.persisted) {
        this.changed('resume');
      }
    }, { signal: this.abortController.signal });
    if (typeof MutationObserver !== 'undefined') {
      this.observer = new MutationObserver(() => {
        if (!root.isConnected) {
          this.destroy();
        }
      });
      this.observer.observe(document.body, { childList: true, subtree: true });
    }
  }

  mount(key, create) {
    if (this.destroyed) {
      return null;
    }
    if (!this.features.has(key)) {
      this.features.set(key, create());
    }
    return this.features.get(key);
  }

  changed(type = 'values') {
    if (this.destroyed) {
      return;
    }
    this.revision += 1;
    if (['values', 'context', 'files'].includes(type)) {
      this.previewChanged = true;
    }
    this.dispatchEvent(new Event(type));
  }

  buildPreviewUrl(format = null) {
    if (this.previewUri === '') {
      return null;
    }
    const url = new URL(this.previewUri, window.location.href);
    if (this.controls?.fields.length > 0) {
      url.searchParams.set('componentVariantValues', JSON.stringify(this.controls.collectValues()));
    }
    if (this.controls?.slotFields.length > 0) {
      url.searchParams.set('componentVariantSlots', JSON.stringify(this.controls.collectSlotValues()));
    }
    if (format !== null) {
      url.searchParams.set('frontendStudioPreviewFormat', format);
    }
    return url;
  }

  fileAction(type, action = 'save') {
    top.document.dispatchEvent(new CustomEvent(`frontend-studio:component-file-action-${type}`, {
      detail: { action, variantIdentifier: this.variantIdentifier },
    }));
  }

  destroy() {
    if (this.destroyed) {
      return;
    }
    this.destroyed = true;
    this.revision += 1;
    this.abortController.abort();
    this.observer?.disconnect();
    this.features.forEach((feature) => feature?.destroy());
    this.features.clear();
    views.delete(this.root);
  }
}

export function getVariantState(root) {
  root = root.closest('[data-frontend-studio-variant-view]') || root.closest('[data-frontend-studio-variant-sidebar]') || root;
  if (!views.has(root)) {
    views.set(root, new VariantState(root));
  }
  return views.get(root);
}
