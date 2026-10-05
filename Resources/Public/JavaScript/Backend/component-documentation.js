import VariantFeature from '@andersundsehr/frontend-studio/backend/variant-lifecycle.js';
import { canEditRichText, createEditor, renderMarkdown } from '@andersundsehr/frontend-studio/backend/markdown-editor.bundle.js';

export default class ComponentDocumentation extends VariantFeature {
  constructor(root, view) {
    super(root, view);
    this.source = root.querySelector('[data-doc-source]');
    this.rich = root.querySelector('[data-doc-rich]');
    this.preview = root.querySelector('[data-doc-preview]');
    this.status = root.querySelector('[data-doc-status]');
    this.saveButton = root.querySelector('[data-doc-save]');
    this.modeButton = root.querySelector('[data-doc-mode]');
    this.toolbar = root.querySelector('[data-doc-toolbar]');
    this.baseline = '';
    this.loaded = false;
    this.pending = false;
    this.readOnly = true;
    this.listen(this.source, 'input', () => this.changed());
    this.listen(this.saveButton, 'click', () => this.save());
    this.listen(this.modeButton, 'click', () => this.toggleMode());
    this.listen(root.querySelector('[data-doc-reload]'), 'click', () => {
      if (!this.dirty || window.confirm('Discard unsaved documentation changes and reload?')) this.load();
    });
    this.listen(this.toolbar, 'click', (event) => {
      const button = event.target.closest('[data-doc-command]');
      if (button) this.editor?.command(button.dataset.docCommand);
    });
    this.listen(window, 'beforeunload', (event) => {
      if (this.dirty) { event.preventDefault(); event.returnValue = ''; }
    });
    // TYPO3 navigation replaces the content frame without necessarily unloading the top window.
    this.navigationGuard = (event) => {
      if (this.dirty && !window.confirm('Discard unsaved documentation changes?')) event.preventDefault();
    };
    this.listen(top.document, 'frontend-studio:before-navigate', this.navigationGuard);
    this.listen(view, 'files', () => { if (!this.dirty && !this.pending) this.load(); });
    this.load();
  }

  get dirty() { return this.loaded && this.source.value !== this.baseline; }

  changed() {
    this.preview.innerHTML = renderMarkdown(this.source.value);
    this.saveButton.disabled = !this.loaded || this.readOnly || this.pending || !this.dirty;
    this.view.documentationDirty = this.dirty;
  }

  async request(method, body) {
    const url = new URL(this.root.dataset.docUri, window.location.href);
    url.searchParams.set('identifier', this.root.dataset.componentIdentifier);
    const response = await fetch(url, {
      method, credentials: 'same-origin', signal: this.abortController.signal,
      ...(body ? { headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ identifier: this.root.dataset.componentIdentifier, ...body }) } : {}),
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.message || `Documentation request failed (${response.status}).`);
    return result;
  }

  async load() {
    if (this.pending) return;
    this.pending = true;
    this.status.textContent = 'Loading documentation…';
    try {
      const result = await this.request('GET');
      if (this.destroyed) return;
      this.editor?.destroy(); this.editor = null;
      this.baseline = this.source.value = result.markdown;
      this.revision = result.revision;
      this.readOnly = result.readOnly;
      this.loaded = true;
      this.source.readOnly = this.readOnly;
      this.saveButton.hidden = this.readOnly;
      this.modeButton.hidden = this.readOnly;
      this.rich.hidden = this.toolbar.hidden = true;
      this.source.hidden = false;
      this.modeButton.textContent = 'Rich text';
      this.status.textContent = this.readOnly ? 'Documentation is read-only in Production.' : 'Documentation is shared by all variants of this component.';
      if (!this.readOnly && canEditRichText(this.source.value)) this.toggleMode();
    } catch (error) {
      if (!this.destroyed) this.status.textContent = error.message;
    } finally {
      this.pending = false;
      if (!this.destroyed) this.changed();
    }
  }

  toggleMode() {
    if (!this.loaded || this.readOnly) return;
    if (this.editor) {
      this.editor.destroy(); this.editor = null;
      this.rich.hidden = this.toolbar.hidden = true;
      this.source.hidden = false;
      this.modeButton.textContent = 'Rich text';
      return;
    }
    if (!canEditRichText(this.source.value)) {
      this.status.textContent = 'Keep editing in Markdown source: rich text would change the existing Markdown formatting or unsupported syntax.';
      return;
    }
    this.rich.hidden = this.toolbar.hidden = false;
    this.source.hidden = true;
    this.modeButton.textContent = 'Markdown source';
    this.editor = createEditor(this.rich, this.source.value, (markdown) => {
      this.source.value = markdown;
      this.changed();
    });
  }

  async save() {
    if (!this.loaded || this.readOnly || this.pending || !this.dirty) return;
    const markdown = this.source.value;
    this.pending = true;
    this.changed();
    try {
      const result = await this.request('POST', { markdown, revision: this.revision });
      if (this.destroyed) return;
      this.baseline = markdown;
      this.revision = result.revision;
      this.status.textContent = 'Documentation saved.';
    } catch (error) {
      if (!this.destroyed) this.status.textContent = error.message;
    } finally {
      this.pending = false;
      if (!this.destroyed) this.changed();
    }
  }

  destroy() {
    this.editor?.destroy();
    this.view.documentationDirty = false;
    super.destroy();
  }
}
