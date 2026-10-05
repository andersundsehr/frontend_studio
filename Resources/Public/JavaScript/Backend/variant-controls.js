import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import DocumentService from '@typo3/core/document-service.js';
import VariantValues from '@andersundsehr/frontend-studio/backend/variant-values.js';
import { getVariantState } from '@andersundsehr/frontend-studio/backend/variant-state.js';

let activeControls = null;

class VariantControls extends VariantValues {

  constructor(root, view) {
    super(root, view);
    this.saveButton = root.querySelector('[data-frontend-studio-variant-save]');
    this.copyVariantButton = root.querySelector('[data-frontend-studio-variant-copy]');
    this.resetButton = root.querySelector('[data-frontend-studio-variant-reset]');
    this.createTransformerButton = root.querySelector('[data-frontend-studio-create-transformer]');
    this.saveState = root.querySelector('[data-frontend-studio-variant-save-state]');
    this.root.querySelectorAll('[data-control-description-toggle]').forEach((button) => {
      const description = button.closest('[data-control-row]').querySelector('[data-control-description]');
      button.disabled = !description?.textContent.trim();
      this.listen(button, 'click', () => {
        description.hidden = !description.hidden;
        button.setAttribute('aria-expanded', String(!description.hidden));
      });
    });
    this.root.querySelectorAll('[data-control-type-toggle]').forEach((button) => {
      this.listen(button, 'click', () => {
        const expanded = button.getAttribute('aria-expanded') !== 'true';
        button.setAttribute('aria-expanded', String(expanded));
        button.querySelector('code').textContent = expanded ? button.dataset.fullType : button.dataset.shortType;
      });
    });
    this.listen(root, 'keydown', (event) => {
      if (event.key !== 'Escape') {
        return;
      }
      const row = event.target.closest('[data-control-row]');
      row?.querySelectorAll('[data-control-description]').forEach((description) => { description.hidden = true; });
      row?.querySelectorAll('[data-control-description-toggle]').forEach((button) => button.setAttribute('aria-expanded', 'false'));
      row?.querySelectorAll('[data-control-type-toggle]').forEach((button) => {
        button.setAttribute('aria-expanded', 'false');
        button.querySelector('code').textContent = button.dataset.shortType;
      });
    });
    this.saving = false;
    this.copying = false;
    view.controls = this;
    activeControls ??= this;
    this.updateDirtyState();
    [...this.fields, ...this.slotFields].forEach((field) => {
      ['input', 'change'].forEach((type) => this.listen(field, type, () => {
        this.updateDirtyState();
        view.changed();
      }));
    });
    this.listen(this.saveButton, 'click', () => this.saveValues());
    this.listen(this.copyVariantButton, 'click', () => this.promptCopyVariant());
    this.listen(this.resetButton, 'click', () => this.resetValues());
    this.listen(this.createTransformerButton, 'click', () => this.createTransformer());
    ['focusin', 'pointerdown'].forEach((type) => this.listen(view.root, type, () => { activeControls = this; }));
    this.listen(document, 'keydown', (event) => {
      if (activeControls === this && (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
        event.preventDefault();
        this.saveValues();
      }
    });
    this.ready = this.mountControls().then(() => {
      if (!this.destroyed) {
        this.updateDirtyState();
        view.changed('controls');
      }
    }).catch((error) => {
      Notification.error('Control initialization failed', error.message || String(error));
    });
    view.changed('controls');
  }

  destroy() {
    if (activeControls === this) {
      activeControls = null;
    }
    if (this.view.controls === this) {
      this.view.controls = null;
    }
    super.destroy();
  }

  updateDirtyState() {
    this.root.querySelectorAll('[data-transformer-group]').forEach((group) => {
      const summary = group.querySelector('[data-transformer-summary]');
      const values = [...group.querySelectorAll('[data-frontend-studio-variant-value]')].map((field) => {
        const value = this.readFieldValue(field);
        return typeof value === 'string' ? value : JSON.stringify(value);
      });
      summary.textContent = values.join(', ') || '(empty)';
      summary.title = summary.textContent;
    });
    if (this.copyVariantButton !== null) {
      this.copyVariantButton.disabled = !this.controlsReady || this.copying;
    }
    this.view.hasUnsavedChanges = JSON.stringify(this.collectValues()) !== JSON.stringify(this.savedValues)
      || JSON.stringify(this.collectSlotValues()) !== JSON.stringify(this.savedSlots);
    const hasInvalidFields = [...this.fields, ...this.slotFields].some((field) => !this.validateField(field));

    if (this.saveState !== null) {
      this.saveState.hidden = !this.view.hasUnsavedChanges;
    }

    if (this.saveButton !== null) {
      this.saveButton.disabled = !this.controlsReady || this.saving || !this.view.hasUnsavedChanges;
      this.saveButton.classList.toggle('frontend-studio-variant-save-invalid', this.view.hasUnsavedChanges && hasInvalidFields);
      this.saveButton.setAttribute('aria-disabled', (!this.controlsReady || this.saving || !this.view.hasUnsavedChanges || hasInvalidFields).toString());
    }

    if (this.resetButton !== null) {
      this.resetButton.disabled = !this.controlsReady || !this.view.hasUnsavedChanges;
    }
  }

  resetValues() {
    if (!this.controlsReady) {
      return;
    }
    this.fields.forEach((field) => {
      const name = field.dataset.fixtureName || field.name || '';
      if (name === '' || this.initialFieldValues[name] === undefined) {
        return;
      }

      this.writeFieldValue(field, this.initialFieldValues[name]);
    });
    this.slotFields.forEach((field) => {
      const name = field.dataset.slotName || field.name || '';
      if (name !== '') {
        this.writeFieldValue(field, this.initialSlotValues[name] || '');
      }
    });

    this.updateDirtyState();
    this.view.changed();
  }

  promptCopyVariant() {
    if (this.view.variantIdentifier === '') {
      return;
    }

    const variantName = this.view.variantIdentifier.split(':').slice(2).join(':');
    const name = window.prompt('New variant name', `${variantName} copy`);
    if (name === null) {
      return;
    }

    this.copyVariant(name);
  }

  async createTransformer() {
    if (this.view.variantIdentifier === '' || this.createTransformerButton === null || this.createTransformerButton.disabled) {
      return;
    }
    this.createTransformerButton.disabled = true;
    this.view.fileAction('started', 'create-transformer');
    try {
      const response = await new AjaxRequest(this.root.dataset.createTransformerUri)
        .post({ identifier: this.view.variantIdentifier }, { signal: this.abortController.signal });
      const payload = await response.resolve();
      if (this.destroyed) {
        return;
      }
      if (payload.success !== true) {
        throw new Error(payload.message || 'Could not create the transformer file.');
      }
      Notification.info('Transformer template created', `${payload.path}. ${payload.hasTodos ? 'Implement the TODO transformations before using this component.' : 'Review the generated transformation and its JSON input.'}`);
      window.location.reload();
    } catch (error) {
      this.view.fileAction('cancelled', 'create-transformer');
      const payload = typeof error?.resolve === 'function' ? await error.resolve() : null;
      if (!this.destroyed) {
        Notification.error('Transformer creation failed', payload?.message || error?.message || 'Could not create the transformer file.');
        this.createTransformerButton.disabled = false;
      }
    }
  }

  async saveValues() {
    if (!this.controlsReady || this.view.variantIdentifier === '' || this.saveButton === null || !this.view.hasUnsavedChanges || this.saving) {
      return;
    }
    const invalidField = [...this.fields, ...this.slotFields].find((field) => !this.validateField(field, true));
    if (invalidField !== undefined) {
      invalidField.focus();
      return;
    }
    const values = this.collectValues();
    const slots = this.collectSlotValues();
    const fieldValues = this.collectFieldValues();
    this.saving = true;
    this.updateDirtyState();
    this.view.fileAction('started');
    try {
      const response = await new AjaxRequest(this.root.dataset.saveUri)
        .post({ identifier: this.view.variantIdentifier, values, slots }, { signal: this.abortController.signal });
      const payload = await response.resolve();
      if (this.destroyed) {
        return;
      }
      if (payload.success !== true || payload.variant === undefined) {
        throw new Error(payload.message || 'The variant values could not be saved.');
      }
      this.savedValues = values;
      this.savedSlots = slots;
      this.initialFieldValues = fieldValues;
      this.initialSlotValues = slots;
      this.fields.forEach((field) => {
        const name = field.dataset.fixtureName || field.name || '';
        field.dataset.fixtureValueDefined = this.hasNestedValue(values, name) ? 'true' : 'false';
      });
      Notification.success('Variant saved', 'The variant values were written to the fixture file.');
      this.view.changed('saved');
    } catch (error) {
      this.view.fileAction('cancelled');
      const payload = typeof error?.resolve === 'function' ? await error.resolve() : null;
      if (!this.destroyed) {
        Notification.error('Variant save failed', payload?.message || error?.message || 'The variant values could not be saved.');
      }
    } finally {
      this.saving = false;
      if (!this.destroyed) {
        this.updateDirtyState();
      }
    }
  }

  async copyVariant(name) {
    if (!this.controlsReady || this.view.variantIdentifier === '' || this.copyVariantButton === null || this.copying
      || ![...this.fields, ...this.slotFields].every((field) => this.validateField(field, true))) {
      return;
    }
    this.copying = true;
    this.copyVariantButton.disabled = true;
    this.view.fileAction('started', 'copy');
    try {
      const response = await new AjaxRequest(this.root.dataset.copyUri)
        .post({ identifier: this.view.variantIdentifier, name, values: this.collectValues(), slots: this.collectSlotValues() },
          { signal: this.abortController.signal });
      const payload = await response.resolve();
      if (this.destroyed) {
        return;
      }
      if (payload.success !== true || payload.variant === undefined) {
        throw new Error(payload.message || 'The variant could not be copied.');
      }
      Notification.success('Variant copied', 'The variant values were written to a new fixture variant.');
      top.document.dispatchEvent(new CustomEvent('frontend-studio:component-files-changed', {
        detail: { variantIdentifier: payload.variant.identifier },
      }));
      const variantUrl = new URL(window.location.href);
      variantUrl.searchParams.set('componentVariant', payload.variant.identifier);
      window.location.href = variantUrl.toString();
    } catch (error) {
      this.view.fileAction('cancelled', 'copy');
      const payload = typeof error?.resolve === 'function' ? await error.resolve() : null;
      if (!this.destroyed) {
        Notification.error('Variant copy failed', payload?.message || error?.message || 'The variant could not be copied.');
      }
    } finally {
      this.copying = false;
      if (!this.destroyed) {
        this.copyVariantButton.disabled = false;
      }
    }
  }

  static initialize() {
    document.querySelectorAll('[data-frontend-studio-variant-controls]').forEach((root) => {
      const view = getVariantState(root);
      view.mount(root, () => new VariantControls(root, view));
    });
  }
}

export default VariantControls;

DocumentService.ready().then(() => VariantControls.initialize());
