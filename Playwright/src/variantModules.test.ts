import assert from 'node:assert/strict';
import { test } from 'node:test';
import { backendModules } from './backendModules.ts';

function element(dataset: Record<string, string> = {}) {
  const classes = new Set<string>();
  const selectors = new Map<string, any>();
  return Object.assign(new EventTarget(), {
    dataset, selectors, isConnected: true, value: '', checked: false, valueAsNumber: NaN,
    disabled: false, hidden: false, innerHTML: '', textContent: '', src: '',
    querySelector: (selector: string) => selectors.get(selector) ?? null,
    querySelectorAll: (selector: string) => selectors.get(selector) ?? [],
    closest: (_selector: string): any => null,
    classList: {
      contains: (name: string) => classes.has(name),
      toggle: (name: string, enabled: boolean) => enabled ? classes.add(name) : classes.delete(name),
    },
    setAttribute: (_name: string, _value: string) => {},
    checkValidity: () => true, reportValidity: () => true, focus: () => {},
  });
}

function environment(roots: any[] = []) {
  const document = Object.assign(new EventTarget(), { querySelectorAll: () => roots });
  const window = Object.assign(new EventTarget(), {
    document, setTimeout, clearTimeout,
    location: { href: 'https://example.test/typo3/module?componentVariant=site:card:Default', reload: () => {} },
  });
  return { document, window, top: window };
}

function controlsRoot() {
  const root = element({ variantIdentifier: 'site:card:Default' });
  const field = element({ fixtureName: 'title', fixtureType: 'string', fixtureValueDefined: 'true' });
  field.value = 'Saved';
  const save = element();
  const reset = element();
  const copy = element();
  root.selectors.set('[data-frontend-studio-variant-value]', [field]);
  root.selectors.set('[data-frontend-studio-variant-slot]', []);
  root.selectors.set('[data-frontend-studio-variant-save]', save);
  root.selectors.set('[data-frontend-studio-variant-reset]', reset);
  root.selectors.set('[data-frontend-studio-variant-copy]', copy);
  return { root, field, save, reset, copy };
}

test('isolated controls initialize once without a preview and remove listeners on cleanup', async () => {
  const { root, field, save, reset } = controlsRoot();
  const env = environment([root]);
  const modules = backendModules(env);
  const { default: Controls } = await modules.import('variant-controls.js');
  const { getVariantState } = await modules.import('variant-state.js');
  Controls.initialize();
  const view = getVariantState(root);
  Controls.initialize();
  assert.equal(view.features.size, 1);
  const revision = view.revision;
  field.value = 'Changed';
  field.dispatchEvent(new Event('input'));
  assert.equal(view.revision, revision + 1);
  assert.equal(view.hasUnsavedChanges, true);
  assert.equal(save.disabled, false);
  reset.dispatchEvent(new Event('click'));
  assert.equal(field.value, 'Saved');
  assert.equal(view.hasUnsavedChanges, false);
  env.window.dispatchEvent(new Event('pagehide'));
  field.value = 'After cleanup';
  const finalRevision = view.revision;
  field.dispatchEvent(new Event('input'));
  assert.equal(view.revision, finalRevision);
  assert.equal(view.destroyed, true);
  assert.ok([...modules.loaded].every((name) => !/variant-(html|usage|preview|sidebar|file-watcher)\.js$/.test(name)));
});

test('value serialization preserves false, zero, null, nested values and omitted defaults', async () => {
  const root = element();
  const fields = [
    element({ fixtureName: 'enabled', fixtureType: 'bool', fixtureValueDefined: 'true' }),
    element({ fixtureName: 'count', fixtureType: 'int', fixtureValueDefined: 'true' }),
    element({ fixtureName: 'nullable', fixtureType: 'string', fixtureValueNull: 'true', fixtureValueDefined: 'true' }),
    element({ fixtureName: 'payload.items', fixtureType: 'array', fixtureValueDefined: 'true' }),
    element({ fixtureName: 'defaultValue', fixtureType: 'string' }),
  ];
  fields[1].valueAsNumber = 0;
  fields[3].value = '["first", "second"]';
  fields[4].value = 'Default';
  root.selectors.set('[data-frontend-studio-variant-value]', fields);
  root.selectors.set('[data-frontend-studio-variant-slot]', []);
  const { default: Values } = await backendModules().import('variant-values.js');
  const values = new Values(root, {});
  assert.deepEqual(JSON.parse(JSON.stringify(values.collectValues())), {
    enabled: false, count: 0, nullable: null, payload: { items: ['first', 'second'] },
  });
  fields[4].value = 'Override';
  fields[3].value = 'Incomplete JSON';
  const changed = values.collectValues();
  assert.equal(changed.defaultValue, 'Override');
  assert.equal(changed.payload.items, 'Incomplete JSON');
  values.writeFieldValue(fields[0], true);
  values.writeFieldValue(fields[2], null);
  values.writeFieldValue(fields[3], ['restored']);
  assert.equal(fields[0].checked, true);
  assert.equal(values.readFieldValue(fields[2]), null);
  assert.equal(fields[3].value, '[\n  "restored"\n]');
  values.destroy();
});

test('only the active view handles Save and edits during a pending save stay dirty', async () => {
  const first = controlsRoot();
  const second = controlsRoot();
  const env = environment([first.root, second.root]);
  const requests: { payload: any; resolve: (value: any) => void }[] = [];
  class AjaxRequest {
    post(payload: any) {
      return new Promise((resolve) => requests.push({ payload, resolve }));
    }
  }
  const modules = backendModules({ ...env, TYPO3: { settings: { ajaxUrls: { frontend_studio_component_tree_update_variant_values: '/save' } } } }, {
    '@typo3/core/ajax/ajax-request.js': AjaxRequest,
  });
  const { default: Controls } = await modules.import('variant-controls.js');
  const { getVariantState } = await modules.import('variant-state.js');
  Controls.initialize();
  first.field.value = 'First';
  first.field.dispatchEvent(new Event('input'));
  second.field.value = 'Submitted';
  second.field.dispatchEvent(new Event('input'));
  second.root.dispatchEvent(new Event('focusin'));
  const shortcut = () => env.document.dispatchEvent(Object.assign(new Event('keydown'), { ctrlKey: true, key: 's' }));
  shortcut();
  shortcut();
  assert.equal(requests.length, 1);
  assert.equal(requests[0].payload.values.title, 'Submitted');
  second.field.value = 'Changed while saving';
  second.field.dispatchEvent(new Event('input'));
  assert.equal(second.save.disabled, true);
  requests[0].resolve({ resolve: async () => ({ success: true, variant: {} }) });
  await new Promise(setImmediate);
  assert.equal(getVariantState(second.root).hasUnsavedChanges, true);
  second.reset.dispatchEvent(new Event('click'));
  assert.equal(second.field.value, 'Submitted');
  assert.equal(getVariantState(second.root).hasUnsavedChanges, false);
  env.window.dispatchEvent(new Event('pagehide'));
});

test('cleanup aborts a pending save and ignores its late response', async () => {
  const { root, field } = controlsRoot();
  const env = environment([root]);
  let resolve!: (response: any) => void;
  let signal!: AbortSignal;
  const notifications: unknown[] = [];
  class AjaxRequest {
    post(_payload: unknown, options: { signal: AbortSignal }) {
      signal = options.signal;
      return new Promise((done) => { resolve = done; });
    }
  }
  const modules = backendModules({ ...env, TYPO3: { settings: { ajaxUrls: {} } } }, {
    '@typo3/core/ajax/ajax-request.js': AjaxRequest,
    '@typo3/backend/notification.js': { success: (...args: unknown[]) => notifications.push(args), error: () => {} },
  });
  const { default: Controls } = await modules.import('variant-controls.js');
  const { getVariantState } = await modules.import('variant-state.js');
  Controls.initialize();
  const view = getVariantState(root);
  field.value = 'Changed';
  field.dispatchEvent(new Event('input'));
  const pending = view.controls.saveValues();
  view.destroy();
  assert.equal(signal.aborted, true);
  resolve({ resolve: async () => ({ success: true, variant: {} }) });
  await pending;
  assert.equal(notifications.length, 0);
});

test('panel requests ignore earlier contexts and cleanup aborts requests and timers', async () => {
  const root = element();
  const container = element();
  const status = element();
  root.selectors.set('[data-frontend-studio-variant-html]', container);
  root.selectors.set('[data-frontend-studio-variant-html-status]', status);
  const env = environment();
  const responses: { resolve: (response: any) => void; signal: AbortSignal }[] = [];
  const modules = backendModules({ ...env, fetch: (_url: string, options: { signal: AbortSignal }) =>
    new Promise((resolve) => responses.push({ resolve, signal: options.signal })) });
  const { VariantState } = await modules.import('variant-state.js');
  const { default: Html } = await modules.import('variant-html.js');
  const view = new VariantState(element({ previewUri: '/preview?site=first' }));
  const panel = view.mount(root, () => new Html(root, view));
  const first = panel.refresh();
  view.previewUri = '/preview?site=second';
  view.changed('context');
  const second = panel.refresh();
  assert.equal(responses[0].signal.aborted, true);
  responses[1].resolve({ ok: true, text: async () => 'Second context' });
  await second;
  responses[0].resolve({ ok: true, text: async () => 'Stale first context' });
  await first;
  assert.equal(container.innerHTML, 'Second context');
  panel.invalidate();
  const third = panel.refresh();
  panel.schedule(10000);
  view.destroy();
  assert.equal(responses[2].signal.aborted, true);
  responses[2].resolve({ ok: true, text: async () => 'After cleanup' });
  await third;
  assert.equal(container.innerHTML, 'Second context');
});

test('panel failures show actionable messages and allow retry', async () => {
  const root = element();
  const status = element();
  const container = element();
  root.selectors.set('[data-frontend-studio-variant-html]', container);
  root.selectors.set('[data-frontend-studio-variant-html-status]', status);
  let requests = 0;
  const modules = backendModules({ ...environment(), fetch: async () => {
    requests++;
    return requests === 1
      ? { ok: false, status: 422, text: async () => '{"message":"A required transformer is missing."}' }
      : { ok: true, text: async () => 'Recovered preview' };
  } });
  const { VariantState } = await modules.import('variant-state.js');
  const { default: Html } = await modules.import('variant-html.js');
  const view = new VariantState(element({ previewUri: '/preview' }));
  const panel = view.mount(root, () => new Html(root, view));
  await panel.refresh();
  assert.equal(status.textContent, 'A required transformer is missing.');
  assert.equal(status.classList.contains('is-error'), true);
  await panel.refresh();
  assert.equal(requests, 2);
  assert.equal(container.innerHTML, 'Recovered preview');
  assert.equal(status.hidden, true);
  view.destroy();
});

test('sidebar loads optional panel modules on activation and remains usable without a preview', async () => {
  const root = element({ activeTab: 'values' });
  const names = ['values', 'html', 'template', 'usage'];
  const buttons = names.map((name) => element({ frontendStudioVariantTab: name }));
  const panels = names.map((name) => element({ frontendStudioVariantTabPanel: name }));
  panels[1].selectors.set('[data-frontend-studio-variant-html]', element());
  panels[1].selectors.set('[data-frontend-studio-variant-html-status]', element());
  panels[3].selectors.set('[data-frontend-studio-fluid-usage-block]', []);
  root.selectors.set('[data-frontend-studio-variant-tab]', buttons);
  root.selectors.set('[data-frontend-studio-variant-tab-panel]', panels);
  const env = environment([root]);
  let fetches = 0;
  const modules = backendModules({ ...env, fetch: () => { fetches++; throw new Error('No preview URI'); } });
  const { default: Sidebar } = await modules.import('variant-sidebar.js');
  const { getVariantState } = await modules.import('variant-state.js');
  Sidebar.initialize();
  Sidebar.initialize();
  const view = getVariantState(root);
  const sidebar = view.features.get(root);
  assert.equal(view.features.size, 1);
  assert.ok([...modules.loaded].every((name) => !/variant-(html|usage|sidebar-resize)\.js$/.test(name)));
  await sidebar.activateTab('template');
  assert.equal(panels[2].hidden, false);
  assert.ok([...modules.loaded].every((name) => !/variant-(html|usage)\.js$/.test(name)));
  await sidebar.activateTab('html');
  assert.ok([...modules.loaded].some((name) => name.endsWith('/variant-html.js')));
  assert.ok([...modules.loaded].every((name) => !name.endsWith('/variant-usage.js')));
  await sidebar.activateTab('usage');
  assert.ok([...modules.loaded].some((name) => name.endsWith('/variant-usage.js')));
  assert.equal(fetches, 0);
  view.destroy();
});

test('host initialization deduplicates the change stream and cleanup closes it', async () => {
  const root = element({ variantIdentifier: 'site:card:Default', componentChangeStreamUri: '/changes' });
  const env = environment([root]);
  let streams = 0;
  let closes = 0;
  let reloads = 0;
  env.window.location.reload = () => { reloads++; };
  class EventSource extends EventTarget {
    constructor(_url: string) { super(); streams++; }
    close() { closes++; }
  }
  const modules = backendModules({ ...env, EventSource });
  const { default: View } = await modules.import('variant-view.js');
  const { getVariantState } = await modules.import('variant-state.js');
  await Promise.all([View.initialize(), View.initialize()]);
  assert.equal(streams, 1);
  assert.ok([...modules.loaded].every((name) => !name.endsWith('/variant-preview.js')));
  const view = getVariantState(root);
  const watcher = view.features.get('watcher');
  const changed = () => watcher.source.dispatchEvent(Object.assign(new Event('component-files-changed'), {
    data: JSON.stringify({ componentIdentifiers: ['site:card'] }),
  }));
  view.fileAction('started');
  changed();
  assert.equal(reloads, 0);
  view.hasUnsavedChanges = true;
  changed();
  assert.equal(reloads, 0);
  view.hasUnsavedChanges = false;
  changed();
  assert.equal(reloads, 1);
  env.window.dispatchEvent(new Event('pagehide'));
  assert.equal(closes, 1);
  changed();
  assert.equal(reloads, 1);
});
