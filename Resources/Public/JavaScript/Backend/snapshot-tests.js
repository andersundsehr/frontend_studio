import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

export function belongsToScope(identifier, scope = '', type = '') {
  if (scope === '' || identifier === scope) return true;
  if (type === 'variant') return false;
  return identifier.startsWith(scope + (type === 'component' ? ':' : ''));
}

export default class SnapshotTests {
  constructor(changed, request = null, openComponent = null) {
    this.changed = changed;
    this.openComponent = openComponent;
    this.enabled = globalThis.TYPO3?.settings?.frontendStudio?.snapshotTestingEnabled ?? true;
    this.readOnly = globalThis.TYPO3?.settings?.frontendStudio?.snapshotReadOnly ?? false;
    this.request = request ?? (async (body, signal) => {
      try {
        const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.frontend_studio_snapshot).post(body, { signal });
        const payload = await response.resolve();
        if (payload.success !== true) throw new Error(payload.message || 'Snapshot request failed.');
        return payload;
      } catch (error) {
        const payload = typeof error?.resolve === 'function' ? await error.resolve() : null;
        throw new Error(payload?.message || error?.message || 'Snapshot request failed.');
      }
    });
    this.context = { site: '', language: '' };
    this.generation = 0;
    this.results = new Map();
    this.catalog = [];
    this.active = false;
    this.completed = 0;
    this.total = 0;
    this.errors = new Map();
  }

  setContext(context) {
    if (context.site !== this.context.site || context.language !== this.context.language) {
      this.context = context;
      this.invalidate();
    }
  }

  invalidate() {
    this.generation++;
    this.abort?.abort();
    this.active = false;
    this.results.clear();
    this.errors.clear();
    this.catalog = [];
    this.completed = this.total = 0;
    this.changed();
  }

  identifiers(scope = '', type = '') {
    return this.catalog.filter((identifier) => belongsToScope(identifier, scope, type));
  }

  status(scope = '', type = '') {
    const identifiers = this.identifiers(scope, type);
    if ([...this.errors.keys()].some((id) => belongsToScope(id, scope, type)) || identifiers.some((id) => this.results.has(id) && this.results.get(id).status !== 'passed')) return 'failed';
    if (identifiers.length > 0 && identifiers.every((id) => this.results.get(id)?.status === 'passed')) return 'passed';
    return 'not-run';
  }

  failures(scope = '', type = '') {
    const results = this.identifiers(scope, type).map((id) => this.results.get(id)).filter((result) => result && result.status !== 'passed');
    for (const [id, message] of this.errors) {
      if (belongsToScope(id, scope, type)) results.unshift({ identifier: id || 'All variants', status: 'error', message, expected: '', actual: '' });
    }
    return results;
  }

  get passed() {
    return [...this.results.values()].filter((result) => result.status === 'passed').length;
  }

  async run(scope = '') {
    if (!this.enabled || this.active) return;
    const generation = ++this.generation;
    this.abort = new AbortController();
    this.active = true;
    this.completed = this.total = 0;
    const type = scope.split(':').length > 2 ? 'variant' : (scope.endsWith(':') || scope.endsWith('.') ? '' : 'component');
    for (const id of this.errors.keys()) if (belongsToScope(id, scope, type)) this.errors.delete(id);
    for (const id of this.results.keys()) if (belongsToScope(id, scope, type)) this.results.delete(id);
    const context = { ...this.context };
    this.changed();
    try {
      if (!context.site || !context.language) throw new Error('Select a preview site and language before testing.');
      const plan = await this.request({ ...context, scope, operation: 'discover' }, this.abort.signal);
      if (generation !== this.generation) return;
      this.catalog = plan.catalog;
      this.total = plan.identifiers.length;
      const catalog = new Set(this.catalog);
      for (const id of this.results.keys()) if (!catalog.has(id)) this.results.delete(id);
      for (const id of plan.identifiers) this.results.delete(id);
      this.changed();
      for (const identifier of plan.identifiers) {
        let result;
        try {
          const payload = await this.request({ ...context, scope: identifier, operation: 'run' }, this.abort.signal);
          result = payload.result;
          if (!result || result.identifier !== identifier) throw new Error('Invalid snapshot response.');
        } catch (error) {
          result = { identifier, status: 'error', message: error?.message || 'Snapshot request failed.', expected: '', actual: '' };
        }
        if (generation !== this.generation) return;
        this.results.set(identifier, result);
        this.completed++;
        this.changed();
      }
    } catch (error) {
      if (generation === this.generation) this.errors.set(scope, error?.message || 'Snapshot discovery failed.');
    } finally {
      if (generation === this.generation) {
        this.active = false;
        this.changed();
      }
    }
  }

  async update(identifier, generation = this.generation) {
    if (!this.enabled || this.readOnly) throw new Error('Snapshot updates are unavailable in this backend.');
    if (generation !== this.generation) throw new Error('These results have expired. Close the details and rerun the test.');
    if (this.active) throw new Error('Wait for the current test or update to finish.');
    if (!this.catalog.includes(identifier)) throw new Error('Update one catalog variant at a time.');
    this.abort = new AbortController();
    this.active = true;
    const context = { ...this.context };
    this.changed();
    try {
      if (!context.site || !context.language) throw new Error('Select a preview site and language before updating.');
      const payload = await this.request({ ...context, scope: identifier, operation: 'update' }, this.abort.signal);
      if (generation !== this.generation) return;
      if (!payload.result || payload.result.identifier !== identifier) throw new Error('Invalid snapshot response.');
      if (payload.result.status === 'error') {
        const error = new Error(payload.result.message || 'Snapshot update failed.');
        error.trace = payload.result.trace || '';
        throw error;
      }
      this.results.set(identifier, payload.result);
      this.changed();
      return payload.result;
    } catch (error) {
      if (generation === this.generation) throw error;
    } finally {
      if (generation === this.generation) {
        this.active = false;
        this.changed();
      }
    }
  }

  async showFailures(scope = '', type = '') {
    if (!this.enabled) return;
    const generation = this.generation;
    const { showSnapshotDiff } = await import('./snapshot-diff.js');
    if (generation !== this.generation) return;
    return showSnapshotDiff(this.failures(scope, type), {
      openComponent: this.openComponent, variantIdentifiers: this.catalog, context: { ...this.context },
      readOnly: this.readOnly, canUpdate: () => !this.active && generation === this.generation,
      updateSnapshot: (identifier) => this.update(identifier, generation),
    });
  }
}
