import VariantFeature from '@andersundsehr/frontend-studio/backend/variant-lifecycle.js';

export default class VariantValues extends VariantFeature {
  constructor(root, view) {
    super(root, view);
    this.fields = Array.from(root.querySelectorAll('[data-frontend-studio-variant-value]'));
    this.slotFields = Array.from(root.querySelectorAll('[data-frontend-studio-variant-slot]'));
    this.initialFieldValues = this.collectFieldValues();
    this.initialSlotValues = this.collectSlotValues();
    this.savedValues = this.collectValues();
    this.savedSlots = this.collectSlotValues();
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
        slots[name] = field.value;
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
