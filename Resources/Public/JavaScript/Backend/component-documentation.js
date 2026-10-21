import Notification from '@typo3/backend/notification.js';
import VariantFeature from '@andersundsehr/frontend-studio/backend/variant-lifecycle.js';
import { renderMarkdown } from '@andersundsehr/frontend-studio/backend/markdown-renderer.js';
import { createEditor } from '@andersundsehr/frontend-studio/backend/markdown-editor.js';

export default class ComponentDocumentation extends VariantFeature {
  constructor(root, view) {
    super(root, view);
    this.markdown = '';
    this.rich = root.querySelector('[data-doc-rich]');
    this.actions = root.querySelector('[data-doc-actions]');
    this.status = root.querySelector('[data-doc-status]');
    this.saveButton = root.querySelector('[data-doc-save]');
    this.resetButton = root.querySelector('[data-doc-reset]');
    this.saveState = root.querySelector('[data-doc-save-state]');
    this.editorGeneration = 0;
    this.editorPending = false;
    this.baseline = '';
    this.loaded = false;
    this.pending = false;
    this.reloadRequested = false;
    this.readOnly = true;
    this.listen(this.saveButton, 'click', () => this.save());
    this.listen(this.resetButton, 'click', () => this.reset());
    this.listen(root, 'keydown', (event) => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
        event.preventDefault();
        event.stopPropagation();
        this.save();
      }
    });
    // The floating toolbar lives outside the panel; its save shortcut still belongs to documentation.
    this.listen(root.ownerDocument, 'keydown', (event) => {
      if (root.classList.contains('has-editor-focus') && (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
        event.preventDefault();
        event.stopPropagation();
        this.save();
      }
    }, { capture: true });
    this.listen(window, 'beforeunload', (event) => {
      // Reuse discard approval for this unload unless another navigation guard cancelled it.
      if (this.dirty && (!this.approvedNavigation || this.approvedNavigation.defaultPrevented)) {
        event.preventDefault(); event.returnValue = '';
      }
      this.approvedNavigation = null;
    });
    // TYPO3 navigation replaces the content frame without necessarily unloading the top window.
    this.navigationGuard = (event) => {
      this.approvedNavigation = null;
      if (!this.dirty) return;
      if (!window.confirm('Discard unsaved documentation changes?')) event.preventDefault();
      else this.approvedNavigation = event;
    };
    this.listen(top.document, 'frontend-studio:before-navigate', this.navigationGuard);
    this.listen(view, 'documentation', () => {
      this.reloadRequested = true;
      this.refreshIfClean();
    });
    this.listen(view, 'files', () => { if (!this.dirty && !this.pending) this.load(); });
    this.load();
  }

  get dirty() { return this.loaded && this.markdown !== this.baseline; }

  changed() {
    this.approvedNavigation = null;
    if (this.saveButton) this.saveButton.disabled = !this.loaded || this.readOnly || this.pending || !this.dirty;
    if (this.resetButton) this.resetButton.disabled = !this.loaded || this.pending || !this.dirty;
    if (this.saveState) this.saveState.hidden = !this.dirty;
    this.view.documentationDirty = this.dirty;
    this.refreshIfClean();
  }

  refreshIfClean() {
    if (!this.reloadRequested || this.dirty || this.pending || this.destroyed) return;
    this.reloadRequested = false;
    this.load();
  }

  async request(method, body) {
    const url = new URL(this.root.dataset.docUri, window.location.href);
    url.searchParams.set('identifier', this.root.dataset.componentIdentifier);
    const response = await fetch(url, {
      method, credentials: 'same-origin', signal: this.abortController.signal,
      ...(body ? { headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body: new URLSearchParams({ identifier: this.root.dataset.componentIdentifier, ...body }).toString() } : {}),
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.message || `Documentation request failed (${response.status}).`);
    return result;
  }

  async load() {
    if (this.pending) return;
    this.pending = true;
    const sourceAtStart = this.markdown;
    const previousStatus = this.status.textContent;
    this.status.textContent = 'Loading documentation…';
    try {
      const result = await this.request('GET');
      if (this.destroyed) return;
      if (this.loaded && this.markdown !== sourceAtStart) {
        this.status.textContent = 'Documentation was edited while loading. Your edits were kept; reload again to discard them.';
        return;
      }
      if (this.loaded && (this.editor || this.readOnly) && result.revision === this.revision && result.readOnly === this.readOnly) {
        this.status.textContent = previousStatus;
        return;
      }
      const canReuseEditor = this.editor && !result.readOnly;
      if (!canReuseEditor) {
        this.editorGeneration += 1;
        this.editorPending = false;
        await this.editor?.destroy();
        this.editor = null;
        if (this.destroyed) return;
      }
      this.baseline = this.markdown = result.markdown;
      this.revision = result.revision;
      this.readOnly = result.readOnly;
      this.loaded = true;
      if (this.actions) this.actions.hidden = this.readOnly;
      this.status.textContent = '';
      if (canReuseEditor) {
        this.editor.setData(this.baseline);
        this.markdown = this.baseline;
      } else {
        this.rich.innerHTML = renderMarkdown(this.markdown);
        if (!this.readOnly) await this.startEditor();
      }
    } catch (error) {
      if (!this.destroyed) this.status.textContent = error.message;
    } finally {
      this.pending = false;
      if (!this.destroyed) this.changed();
    }
  }

  async startEditor() {
    const source = this.markdown;
    const generation = ++this.editorGeneration;
    this.editorPending = true;
    try {
      const editor = await createEditor(this.rich, source, (markdown) => {
        if (!this.destroyed && !this.readOnly && generation === this.editorGeneration) {
          this.markdown = markdown;
          this.changed();
        }
      }, this.root.querySelector('[data-doc-editor-controls]'));
      if (this.destroyed || generation !== this.editorGeneration) {
        await editor.destroy();
        return;
      }
      this.editor = editor;
    } catch (error) {
      if (!this.destroyed && generation === this.editorGeneration) {
        this.rich.innerHTML = renderMarkdown(this.markdown);
        this.status.textContent = error.message;
      }
    } finally {
      if (!this.destroyed && generation === this.editorGeneration) this.editorPending = false;
    }
  }

  reset() {
    if (!this.loaded || this.pending || !this.dirty) return;
    this.editor?.setData(this.baseline);
    this.markdown = this.baseline;
    if (!this.editor) this.rich.innerHTML = renderMarkdown(this.markdown);
    this.changed();
  }

  async save() {
    if (!this.loaded || this.readOnly || this.pending || !this.dirty) return;
    const markdown = this.markdown;
    this.pending = true;
    this.changed();
    try {
      const result = await this.request('POST', { markdown, revision: this.revision });
      if (this.destroyed) return;
      this.baseline = markdown;
      this.revision = result.revision;
      this.status.textContent = 'Documentation saved.';
      Notification.success('Documentation saved', markdown === '' ? 'The Markdown file was removed.' : 'The documentation was written to the Markdown file.');
    } catch (error) {
      if (!this.destroyed) {
        this.status.textContent = error.message;
        Notification.error('Documentation save failed', error.message);
      }
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
