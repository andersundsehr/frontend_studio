import VariantFeature from '@andersundsehr/frontend-studio/backend/variant-lifecycle.js';
import { canEditRichText, renderMarkdown } from '@andersundsehr/frontend-studio/backend/markdown-editor.bundle.js';
import { createEditor } from '@andersundsehr/frontend-studio/backend/markdown-editor.js';

export default class ComponentDocumentation extends VariantFeature {
  constructor(root, view) {
    super(root, view);
    this.source = root.querySelector('[data-doc-source]');
    this.rich = root.querySelector('[data-doc-rich]');
    this.preview = root.querySelector('[data-doc-preview]');
    this.status = root.querySelector('[data-doc-status]');
    this.saveButton = root.querySelector('[data-doc-save]');
    this.modeButton = root.querySelector('[data-doc-mode]');
    this.editorGeneration = 0;
    this.editorPending = false;
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
    const sourceAtStart = this.source.value;
    this.status.textContent = 'Loading documentation…';
    try {
      const result = await this.request('GET');
      if (this.destroyed) return;
      if (this.loaded && this.source.value !== sourceAtStart) {
        this.status.textContent = 'Documentation was edited while loading. Your edits were kept; reload again to discard them.';
        return;
      }
      this.editorGeneration += 1;
      this.editorPending = false;
      this.editor?.destroy(); this.editor = null;
      this.baseline = this.source.value = result.markdown;
      this.revision = result.revision;
      this.readOnly = result.readOnly;
      this.loaded = true;
      this.source.readOnly = this.readOnly;
      this.saveButton.hidden = this.readOnly;
      this.modeButton.hidden = this.readOnly;
      this.rich.hidden = true;
      this.source.hidden = false;
      this.modeButton.textContent = 'Rich text';
      this.status.textContent = this.readOnly ? 'Documentation is read-only in Production.' : 'Documentation is shared by all variants of this component.';
      if (!this.readOnly && canEditRichText(this.source.value)) await this.toggleMode();
    } catch (error) {
      if (!this.destroyed) this.status.textContent = error.message;
    } finally {
      this.pending = false;
      if (!this.destroyed) this.changed();
    }
  }

  async toggleMode() {
    if (!this.loaded || this.readOnly) return;
    if (this.editor || this.editorPending) {
      this.editorGeneration += 1;
      this.editorPending = false;
      this.editor?.destroy(); this.editor = null;
      this.rich.hidden = true;
      this.source.hidden = false;
      this.modeButton.textContent = 'Rich text';
      return;
    }
    if (!canEditRichText(this.source.value)) {
      this.status.textContent = 'Keep editing in Markdown source: rich text would change the existing Markdown formatting or unsupported syntax.';
      return;
    }
    const source = this.source.value;
    const generation = ++this.editorGeneration;
    this.editorPending = true;
    this.modeButton.textContent = 'Markdown source';
    try {
      const editor = await createEditor(this.rich, source, (markdown) => {
        if (!this.destroyed && generation === this.editorGeneration) {
          this.source.value = markdown;
          this.changed();
        }
      });
      if (this.destroyed || generation !== this.editorGeneration || this.source.value !== source) {
        if (!this.destroyed && generation === this.editorGeneration) {
          this.editorGeneration += 1;
          this.editorPending = false;
          this.modeButton.textContent = 'Rich text';
        }
        await editor.destroy();
        return;
      }
      this.editor = editor;
      this.rich.hidden = false;
      this.source.hidden = true;
    } catch (error) {
      if (!this.destroyed && generation === this.editorGeneration) {
        this.status.textContent = error.message;
      }
    } finally {
      if (!this.destroyed && generation === this.editorGeneration) {
        this.editorPending = false;
        if (!this.editor) this.modeButton.textContent = 'Rich text';
      }
    }
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
    this.editorGeneration += 1;
    this.editorPending = false;
    this.editor?.destroy();
    this.view.documentationDirty = false;
    super.destroy();
  }
}
