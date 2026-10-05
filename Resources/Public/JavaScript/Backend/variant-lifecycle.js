export default class VariantFeature {
  constructor(root, view) {
    this.root = root;
    this.view = view;
    this.abortController = new AbortController();
  }

  get destroyed() {
    return this.abortController.signal.aborted;
  }

  listen(target, type, listener, options = {}) {
    target?.addEventListener(type, listener, { ...options, signal: this.abortController.signal });
  }

  destroy() {
    this.abortController.abort();
  }
}
