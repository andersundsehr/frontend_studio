import VariantFeature from '@andersundsehr/frontend-studio/backend/variant-lifecycle.js';

export default class VariantValues extends VariantFeature {
  constructor(root, view) {
    super(root, view);
    this.adapters = new Map();
    this.controlsReady = true;
    this.fields = Array.from(root.querySelectorAll('[data-frontend-studio-variant-value]'));
    this.slotFields = Array.from(root.querySelectorAll('[data-frontend-studio-variant-slot]'));
    this.initialFieldValues = this.collectFieldValues();
    this.initialSlotValues = this.collectSlotValues();
    this.savedValues = this.collectValues();
    this.savedSlots = this.collectSlotValues();
  }

  async mountControls() {
    const hosts = [...this.root.querySelectorAll('[data-frontend-studio-control]')];
    if (hosts.length === 0) {
      return;
    }
    this.controlsReady = false;
    this.root.inert = true;
    this.updateDirtyState();
    try {
      await Promise.all(hosts.map(async (host) => {
        const field = host.querySelector('[data-frontend-studio-variant-value], [data-frontend-studio-variant-slot]');
        if (field === null) {
          throw new Error('Custom controls must contain one fixture input.');
        }
        const { default: mount } = await import(host.dataset.frontendStudioControl);
        if (this.destroyed) {
          return;
        }
        const adapter = await mount({
          host, field, options: JSON.parse(host.dataset.controlOptions || '{}'),
          signal: this.abortController.signal,
          changed: () => {
            if (this.controlsReady && !this.destroyed) {
              field.dispatchEvent(new Event('input', { bubbles: true }));
            }
          },
        });
        if (this.destroyed) {
          await adapter.destroy();
          return;
        }
        for (const method of ['getValue', 'setValue', 'validate', 'destroy']) {
          if (typeof adapter[method] !== 'function') {
            throw new Error(`Custom control is missing ${method}().`);
          }
        }
        this.adapters.set(field, adapter);
      }));
      if (!this.destroyed) {
        this.initialFieldValues = this.collectFieldValues();
        this.initialSlotValues = this.collectSlotValues();
        this.savedValues = this.collectValues();
        this.savedSlots = this.collectSlotValues();
        this.controlsReady = true;
        this.root.inert = false;
      }
    } catch (error) {
      // Never save a partially mounted editor, or silently fall back to stale input data.
      if (!this.destroyed) {
        this.root.inert = false;
        this.controlError = error;
        this.root.setAttribute('data-control-error', String(error));
        throw error;
      }
    }
  }

  destroy() {
    super.destroy();
    this.adapters.forEach((adapter) => { void adapter.destroy(); });
    this.adapters.clear();
  }

  validateField(field, report = false) {
    const adapter = this.adapters.get(field);
    if (adapter) {
      field.setCustomValidity(adapter.validate() || '');
    }
    return report ? field.reportValidity() : field.checkValidity();
  }

  collectValues() {
    const values = {};

    this.fields.forEach((field) => {
      const name = field.dataset.fixtureName || field.name || '';
      if (name === '') {
        return;
      }

      const value = this.readFieldValue(field);
      if (this.shouldSubmitField(field, name, value)) {
        this.setNestedValue(values, name, value);
      }
    });

    return values;
  }

  setNestedValue(values, name, value) {
    const path = name.split('.');
    const lastSegment = path.pop();
    let target = values;

    path.forEach((segment) => {
      target[segment] ??= {};
      target = target[segment];
    });

    target[lastSegment] = value;
  }

  hasNestedValue(values, name) {
    return name.split('.').every((segment) => {
      if (!Object.prototype.hasOwnProperty.call(values, segment)) {
        return false;
      }
      values = values[segment];
      return true;
    });
  }

  collectFieldValues() {
    const values = {};

    this.fields.forEach((field) => {
      const name = field.dataset.fixtureName || field.name || '';
      if (name === '') {
        return;
      }

      values[name] = this.readFieldValue(field);
    });

    return values;
  }

  collectSlotValues() {
    const slots = {};

    this.slotFields.forEach((field) => {
      const name = field.dataset.slotName || field.name || '';
      if (name !== '') {
        slots[name] = this.readFieldValue(field);
      }
    });

    return slots;
  }

  shouldSubmitField(field, name, value) {
    if (field.dataset.fixtureValueDefined === 'true') {
      return true;
    }

    return JSON.stringify(value) !== JSON.stringify(this.initialFieldValues[name]);
  }

  readFieldValue(field) {
    const adapter = this.adapters.get(field);
    if (adapter) {
      return adapter.getValue();
    }
    const fixtureType = (field.dataset.fixtureType || '').toLowerCase();

    if (fixtureType === 'bool' || fixtureType === 'boolean') {
      return field.checked === true;
    }

    if (field.dataset.fixtureValueNull === 'true') {
      if (field.value === '') {
        return null;
      }

      field.dataset.fixtureValueNull = 'false';
    }

    if (fixtureType === 'int' || fixtureType === 'integer') {
      return Number.isFinite(field.valueAsNumber) ? Math.trunc(field.valueAsNumber) : field.value;
    }

    if (fixtureType === 'float' || fixtureType === 'double') {
      return Number.isFinite(field.valueAsNumber) ? field.valueAsNumber : field.value;
    }

    if (fixtureType === 'null') {
      return field.value.trim() === '' ? null : field.value;
    }

    if (fixtureType === 'datetime') {
      return field.value;
    }

    if (this.isCompoundFixtureType(fixtureType)) {
      try {
        return JSON.parse(field.value);
      } catch {
        return field.value;
      }
    }

    return field.value;
  }

  isCompoundFixtureType(fixtureType) {
    return ['array', 'object', 'stdclass'].includes(fixtureType)
      || fixtureType.startsWith('array<')
      || fixtureType.startsWith('object(');
  }

  writeFieldValue(field, value) {
    const adapter = this.adapters.get(field);
    if (adapter) {
      adapter.setValue(value);
      return;
    }
    field.dataset.fixtureValueNull = value === null ? 'true' : 'false';
    const fixtureType = (field.dataset.fixtureType || '').toLowerCase();

    if (fixtureType === 'bool' || fixtureType === 'boolean') {
      field.checked = value === true;
      return;
    }

    if (fixtureType === 'datetime') {
      const date = new Date(value);
      if (Number.isNaN(date.getTime())) {
        field.value = value;
        return;
      }
      const offset = date.getTimezoneOffset() * 60_000;
      field.value = new Date(date.getTime() - offset).toISOString().slice(0, 16);
      return;
    }

    if (value === null || value === undefined) {
      field.value = '';
      return;
    }

    if (this.isCompoundFixtureType(fixtureType) && typeof value !== 'string') {
      field.value = JSON.stringify(value, null, 2);
      return;
    }

    field.value = String(value);
  }
}
