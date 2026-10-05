import assert from 'node:assert/strict';
import { test } from 'node:test';
import { backendModules } from './backendModules.ts';

function element(dataset: Record<string, string> = {}) {
  const classes = new Set<string>();
  const attributes = new Map<string, string>();
  const selectors = new Map<string, any>();
  return Object.assign(new EventTarget(), {
    dataset, selectors, attributes, isConnected: true, value: '', checked: false, valueAsNumber: NaN,
    disabled: false, hidden: false, innerHTML: '', textContent: '', src: '',
    querySelector: (selector: string) => selectors.get(selector) ?? null,
    querySelectorAll: (selector: string) => selectors.get(selector) ?? [],
    closest: (_selector: string): any => null,
    classList: {
      contains: (name: string) => classes.has(name),
      toggle: (name: string, enabled: boolean) => enabled ? classes.add(name) : classes.delete(name),
    },
    setAttribute: (name: string, value: string) => attributes.set(name, value),
    checkValidity: (): boolean => true, reportValidity: (): boolean => true, focus: () => {},
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
  const root = element({ variantIdentifier: 'site:card:Default', saveUri: '/save', copyUri: '/copy' });
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

async function transformerControls() {
  const root = element({ variantIdentifier: 'site:missingTransformer:Default', createTransformerUri: '/transformer' });
  const button = element();
  root.selectors.set('[data-frontend-studio-create-transformer]', button);
  const env = environment([root]);
  let reloads = 0;
  env.window.location.reload = () => { reloads++; };
  const requests: { url: string; identifier: string; signal: AbortSignal }[] = [];
  const notifications: unknown[][] = [];
  const actions: string[] = [];
  for (const type of ['started', 'cancelled']) {
    env.document.addEventListener(`frontend-studio:component-file-action-${type}`, (event) => {
      actions.push(`${type}:${(event as CustomEvent).detail.action}`);
    });
  }
  let respond!: (response: any) => void;
  let reject!: (error: unknown) => void;
  class AjaxRequest {
    url: string;
    constructor(url: string) { this.url = url; }
    post(payload: { identifier: string }, options: { signal: AbortSignal }) {
      requests.push({ url: this.url, identifier: payload.identifier, signal: options.signal });
      return new Promise((resolve, fail) => { respond = resolve; reject = fail; });
    }
  }
  const modules = backendModules(env, {
    '@typo3/core/ajax/ajax-request.js': AjaxRequest,
    '@typo3/backend/notification.js': {
      info: (...args: unknown[]) => notifications.push(['info', ...args]),
      error: (...args: unknown[]) => notifications.push(['error', ...args]),
    },
  });
  const { default: Controls } = await modules.import('variant-controls.js');
  const { getVariantState } = await modules.import('variant-state.js');
  Controls.initialize();
  const view = getVariantState(root);
  return {
    root, button, view, modules, requests, notifications, actions,
    reloads: () => reloads,
    succeed: (payload: unknown) => respond({ resolve: async () => payload }),
    fail: (error: unknown) => reject(error),
  };
}

for (const hasTodos of [false, true]) {
  test(`isolated transformer controls report ${hasTodos ? 'TODOs' : 'success'} without loading preview modules`, async (t) => {
    const ui = await transformerControls();
    t.after(() => ui.view.destroy());
    ui.button.dispatchEvent(new Event('click'));
    ui.button.dispatchEvent(new Event('click'));
    assert.equal(ui.button.disabled, true);
    assert.equal(ui.requests.length, 1);
    assert.equal(ui.requests[0].url, '/transformer');
    assert.equal(ui.requests[0].identifier, 'site:missingTransformer:Default');
    ui.succeed({ success: true, path: 'MissingTransformer.transformer.php', hasTodos });
    await new Promise(setImmediate);
    assert.deepEqual(ui.notifications, [[
      'info', 'Transformer template created',
      `MissingTransformer.transformer.php. ${hasTodos ? 'Implement the TODO transformations before using this component.' : 'Review the generated transformation and its JSON input.'}`,
    ]]);
    assert.deepEqual(ui.actions, ['started:create-transformer']);
    assert.equal(ui.reloads(), 1);
    assert.ok([...ui.modules.loaded].every((name) => !/variant-(html|usage|preview|sidebar|file-watcher)\.js$/.test(name)));
  });
}

for (const failure of ['payload', 'HTTP', 'network']) {
  test(`transformer ${failure} failure reports an error and allows retry`, async (t) => {
    const ui = await transformerControls();
    t.after(() => ui.view.destroy());
    const pending = ui.view.controls.createTransformer();
    if (failure === 'payload') {
      ui.succeed({ success: false, message: 'File already exists.' });
    } else if (failure === 'HTTP') {
      ui.fail({ resolve: async () => ({ message: 'File already exists.' }) });
    } else {
      ui.fail(new Error('Connection lost.'));
    }
    await pending;
    assert.deepEqual(ui.notifications, [[
      'error', 'Transformer creation failed', failure === 'network' ? 'Connection lost.' : 'File already exists.',
    ]]);
    assert.deepEqual(ui.actions, ['started:create-transformer', 'cancelled:create-transformer']);
    assert.equal(ui.view.ignoreNextComponentFilesChanged, false);
    assert.equal(ui.reloads(), 0);
    assert.equal(ui.button.disabled, false);
    const retry = ui.view.controls.createTransformer();
    assert.equal(ui.requests.length, 2);
    ui.succeed({ success: true, path: 'MissingTransformer.transformer.php', hasTodos: false });
    await retry;
    assert.equal(ui.reloads(), 1);
  });
}

test('cleanup aborts transformer creation and ignores a late success response', async () => {
  const ui = await transformerControls();
  const pending = ui.view.controls.createTransformer();
  ui.view.destroy();
  assert.equal(ui.requests[0].signal.aborted, true);
  ui.succeed({ success: true, path: 'MissingTransformer.transformer.php', hasTodos: false });
  await pending;
  assert.equal(ui.notifications.length, 0);
  assert.equal(ui.reloads(), 0);
});

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

test('cached navigation preserves controls, unsaved edits and the last saved Reset baseline', async (t) => {
  const { root, field, save, reset } = controlsRoot();
  const env = environment([root]);
  const requests: any[] = [];
  class AjaxRequest {
    async post(payload: any) {
      requests.push(payload);
      return { resolve: async () => ({ success: true, variant: {} }) };
    }
  }
  const modules = backendModules(env, {
    '@typo3/core/ajax/ajax-request.js': AjaxRequest,
  });
  const { default: Controls } = await modules.import('variant-controls.js');
  const { getVariantState } = await modules.import('variant-state.js');
  Controls.initialize();
  const view = getVariantState(root);
  const controls = view.controls;
  t.after(() => view.destroy());
  field.value = 'Saved before navigation';
  field.dispatchEvent(new Event('input'));
  save.dispatchEvent(new Event('click'));
  await new Promise(setImmediate);

  for (let visit = 0; visit < 2; visit++) {
    field.value = `Unsaved before navigation ${visit}`;
    field.dispatchEvent(new Event('input'));
    env.window.dispatchEvent(Object.assign(new Event('pagehide'), { persisted: true }));
    assert.equal(view.destroyed, false);
    env.window.dispatchEvent(Object.assign(new Event('pageshow'), { persisted: true }));
    assert.equal(getVariantState(root), view);
    assert.equal(view.controls, controls);
    assert.equal(view.hasUnsavedChanges, true);
    assert.equal(field.value, `Unsaved before navigation ${visit}`);
    reset.dispatchEvent(new Event('click'));
    assert.equal(field.value, 'Saved before navigation');
    assert.equal(view.hasUnsavedChanges, false);
    const revision = view.revision;
    field.value = 'Edited after restoration';
    field.dispatchEvent(new Event('input'));
    assert.equal(view.revision, revision + 1);
    assert.equal(save.disabled, false);
    reset.dispatchEvent(new Event('click'));
  }

  field.value = 'Saved after restoration';
  field.dispatchEvent(new Event('input'));
  env.document.dispatchEvent(Object.assign(new Event('keydown'), { ctrlKey: true, key: 's' }));
  await new Promise(setImmediate);
  assert.equal(requests.length, 2);
  assert.equal(requests[1].values.title, 'Saved after restoration');
  assert.equal(view.hasUnsavedChanges, false);
  env.window.dispatchEvent(Object.assign(new Event('pagehide'), { persisted: false }));
  assert.equal(view.destroyed, true);
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
  const modules = backendModules(env, {
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
  const modules = backendModules(env, {
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

for (const action of ['save', 'copy']) {
  test(`early ${action} suppresses its own SSE change before the watcher import completes`, async (t) => {
    const { root, field } = controlsRoot();
    root.dataset.componentChangeStreamUri = '/changes';
    const env = environment([root]);
    let reloads = 0;
    env.window.location.reload = () => { reloads++; };
    let resolve!: (response: any) => void;
    class AjaxRequest {
      constructor(url: string) { assert.equal(url, action === 'save' ? '/save' : '/copy'); }
      post() { return new Promise((done) => { resolve = done; }); }
    }
    class EventSource extends EventTarget {
      close() {}
    }
    const modules = backendModules({ ...env, EventSource }, {
      '@typo3/core/ajax/ajax-request.js': AjaxRequest,
    });
    const { default: Watcher } = await modules.import('component-file-watcher.js');
    const owner = new Watcher();
    t.after(() => owner.destroy());
    owner.updateModule({ module: 'admin_frontendstudio', componentChangeStreamUri: '/changes' });
    const { default: Controls } = await modules.import('variant-controls.js');
    const { default: View } = await modules.import('variant-view.js');
    const { getVariantState } = await modules.import('variant-state.js');
    Controls.initialize();
    const view = getVariantState(root);
    t.after(() => view.destroy());
    const initializing = View.initialize();
    assert.equal(view.features.has('watcher'), false);
    if (action === 'save') {
      field.value = 'Changed';
      field.dispatchEvent(new Event('input'));
    }
    const pending = action === 'save' ? view.controls.saveValues() : view.controls.copyVariant('New');
    assert.equal(view.features.has('watcher'), false, 'the action must start before the watcher mounts');
    await initializing;
    const changed = () => owner.source.dispatchEvent(Object.assign(new Event('component-files-changed'), {
      data: JSON.stringify({ componentIdentifiers: ['site:card'] }),
    }));
    if (action === 'copy') {
      changed();
      assert.equal(reloads, 0, 'an own file change must not reload while copy navigation is pending');
      assert.equal(new URL(env.window.location.href).searchParams.get('componentVariant'), 'site:card:Default');
    }
    resolve({ resolve: async () => ({ success: true, variant: { identifier: 'site:card:New' } }) });
    await pending;
    assert.equal(view.hasUnsavedChanges, false);
    if (action === 'save') {
      changed();
    } else {
      assert.equal(new URL(env.window.location.href).searchParams.get('componentVariant'), 'site:card:New');
    }
    assert.equal(reloads, 0);
    changed();
    assert.equal(reloads, 1, 'the next external change must still reload a clean view');
  });

  test(`early failed ${action} clears SSE suppression before the watcher import completes`, async (t) => {
    const { root, field } = controlsRoot();
    root.dataset.componentChangeStreamUri = '/changes';
    const env = environment([root]);
    let reloads = 0;
    env.window.location.reload = () => { reloads++; };
    class AjaxRequest {
      async post() { throw new Error('Write failed'); }
    }
    class EventSource extends EventTarget {
      close() {}
    }
    const modules = backendModules({ ...env, EventSource }, {
      '@typo3/core/ajax/ajax-request.js': AjaxRequest,
    });
    const { default: Watcher } = await modules.import('component-file-watcher.js');
    const owner = new Watcher();
    t.after(() => owner.destroy());
    owner.updateModule({ module: 'admin_frontendstudio', componentChangeStreamUri: '/changes' });
    const { default: Controls } = await modules.import('variant-controls.js');
    const { default: View } = await modules.import('variant-view.js');
    const { getVariantState } = await modules.import('variant-state.js');
    Controls.initialize();
    const view = getVariantState(root);
    t.after(() => view.destroy());
    if (action === 'save') {
      field.value = 'Changed';
      field.dispatchEvent(new Event('input'));
    }
    await (action === 'save' ? view.controls.saveValues() : view.controls.copyVariant('New'));
    assert.equal(view.features.has('watcher'), false);
    await View.initialize();
    view.controls.resetValues();
    assert.equal(view.hasUnsavedChanges, false);
    owner.source.dispatchEvent(Object.assign(new Event('component-files-changed'), {
      data: JSON.stringify({ componentIdentifiers: ['site:card'] }),
    }));
    assert.equal(reloads, 1, 'a failed early write must not suppress an external change');
  });
}

for (const order of ['preview first', 'controls first']) {
  test(`initialization keeps the server-rendered iframe navigation (${order})`, async (t) => {
    const { root, field } = controlsRoot();
    root.dataset.previewUri = '/preview?componentVariant=site:card:Default';
    const env = environment([root]);
    let src = new URL(root.dataset.previewUri, env.window.location.href).toString();
    const navigations: string[] = [];
    const iframe = {
      get src() { return src; },
      set src(value: string) { src = new URL(value, env.window.location.href).toString(); navigations.push(src); },
    };
    root.selectors.set('[data-frontend-studio-variant-frame]', iframe);
    const modules = backendModules(env);
    const { default: View } = await modules.import('variant-view.js');
    const { default: Controls } = await modules.import('variant-controls.js');
    const { getVariantState } = await modules.import('variant-state.js');
    const view = getVariantState(root);
    t.after(() => view.destroy());
    if (order === 'controls first') {
      Controls.initialize();
      await View.initialize();
    } else {
      await View.initialize();
      Controls.initialize();
    }
    assert.equal(navigations.length, 0, 'initialization must not navigate the iframe');
    field.value = 'Edited';
    field.dispatchEvent(new Event('input'));
    assert.equal(navigations.length, 1);
    assert.deepEqual(JSON.parse(new URL(src).searchParams.get('componentVariantValues')!), { title: 'Edited' });
    field.dispatchEvent(new Event('change'));
    assert.equal(navigations.length, 1, 'an unchanged URL must not navigate again');
    view.previewUri = '/preview?site=other';
    view.changed('context');
    assert.equal(navigations.length, 2);
    assert.equal(new URL(src).searchParams.get('site'), 'other');
    view.changed('files');
    assert.equal(navigations.length, 3, 'file changes must reload even when the URL is unchanged');
    assert.equal(navigations[2], navigations[1]);
  });
}

test('preview catches edits made before its optional module finishes loading', async (t) => {
  const { root, field } = controlsRoot();
  root.dataset.previewUri = '/preview';
  const env = environment([root]);
  let src = new URL(root.dataset.previewUri, env.window.location.href).toString();
  const navigations: string[] = [];
  root.selectors.set('[data-frontend-studio-variant-frame]', {
    get src() { return src; },
    set src(value: string) { src = value; navigations.push(value); },
  });
  const modules = backendModules(env);
  const { default: View } = await modules.import('variant-view.js');
  const { default: Controls } = await modules.import('variant-controls.js');
  const { getVariantState } = await modules.import('variant-state.js');
  Controls.initialize();
  const view = getVariantState(root);
  t.after(() => view.destroy());
  const initializing = View.initialize();
  assert.equal(view.features.has('preview'), false);
  field.value = 'Edited before preview loads';
  field.dispatchEvent(new Event('input'));
  assert.equal(navigations.length, 0);
  await initializing;
  assert.equal(navigations.length, 1);
  assert.equal(JSON.parse(new URL(src).searchParams.get('componentVariantValues')!).title, field.value);
});

test('late controls initialization refreshes an active HTML panel', async (t) => {
  const { root: host } = controlsRoot();
  host.dataset.previewUri = '/preview';
  const root = element({ activeTab: 'html' });
  root.closest = () => host;
  const panelRoot = element({ frontendStudioVariantTabPanel: 'html' });
  const container = element();
  panelRoot.selectors.set('[data-frontend-studio-variant-html]', container);
  panelRoot.selectors.set('[data-frontend-studio-variant-html-status]', element());
  root.selectors.set('[data-frontend-studio-variant-tab]', [element({ frontendStudioVariantTab: 'html' })]);
  root.selectors.set('[data-frontend-studio-variant-tab-panel]', [panelRoot]);
  const env = environment();
  env.document.querySelectorAll = (selector?: string) => selector?.endsWith('sidebar]') ? [root] : [host];
  const requests: { url: string; signal: AbortSignal; resolve: (response: any) => void }[] = [];
  const modules = backendModules({ ...env, fetch: (url: string, options: { signal: AbortSignal }) =>
    new Promise((resolve) => requests.push({ url, signal: options.signal, resolve })) });
  const { default: Sidebar } = await modules.import('variant-sidebar.js');
  const { default: Controls } = await modules.import('variant-controls.js');
  const { getVariantState } = await modules.import('variant-state.js');
  Sidebar.initialize();
  const view = getVariantState(host);
  t.after(() => view.destroy());
  await view.features.get(root).activateTab('html');
  await new Promise((resolve) => setTimeout(resolve, 10));
  assert.equal(requests.length, 1);
  Controls.initialize();
  await new Promise((resolve) => setTimeout(resolve, 10));
  assert.equal(requests.length, 2, 'the controls-ready signal must refresh the active panel');
  assert.equal(requests[0].signal.aborted, true);
  assert.equal(JSON.parse(new URL(requests[1].url).searchParams.get('componentVariantValues')!).title, 'Saved');
  requests[0].resolve({ ok: true, text: async () => 'Stale preview' });
  requests[1].resolve({ ok: true, text: async () => 'Preview with controls' });
  await new Promise(setImmediate);
  assert.equal(container.innerHTML, 'Preview with controls');
});

for (const identifier of ['site:card:Default', 'site:card:Second']) {
  test(`independent views with watchers isolate suppression for ${identifier}`, async (t) => {
    const first = controlsRoot();
    const second = controlsRoot();
    second.root.dataset.variantIdentifier = identifier;
    first.root.dataset.componentChangeStreamUri = '/changes';
    second.root.dataset.componentChangeStreamUri = '/changes';
    const env = environment([first.root, second.root]);
    let reloads = 0;
    env.window.location.reload = () => { reloads++; };
    class AjaxRequest {
      async post() { return { resolve: async () => ({ success: true, variant: {} }) }; }
    }
    class EventSource extends EventTarget {
      close() {}
    }
    const modules = backendModules({ ...env, EventSource }, {
      '@typo3/core/ajax/ajax-request.js': AjaxRequest,
    });
    const { default: Owner } = await modules.import('component-file-watcher.js');
    const owner = new Owner();
    t.after(() => owner.destroy());
    owner.updateModule({ module: 'admin_frontendstudio', componentChangeStreamUri: '/changes' });
    const ownChanges: boolean[] = [];
    env.document.addEventListener('frontend-studio:component-files-changed', (event) => {
      ownChanges.push((event as CustomEvent).detail.ownAction);
    });
    const { default: Controls } = await modules.import('variant-controls.js');
    const { default: View } = await modules.import('variant-view.js');
    const { getVariantState } = await modules.import('variant-state.js');
    Controls.initialize();
    await View.initialize();
    const firstView = getVariantState(first.root);
    const secondView = getVariantState(second.root);
    t.after(() => { firstView.destroy(); secondView.destroy(); });
    let firstChanges = 0;
    let secondChanges = 0;
    firstView.addEventListener('files', () => { firstChanges++; });
    secondView.addEventListener('files', () => { secondChanges++; });
    const changed = () => owner.source.dispatchEvent(Object.assign(new Event('component-files-changed'), {
      data: JSON.stringify({ componentIdentifiers: ['site:card'] }),
    }));
    first.field.value = 'Saved in first view';
    first.field.dispatchEvent(new Event('input'));
    await firstView.controls.saveValues();
    changed();
    assert.equal(secondChanges, 1, 'a save in another view must not suppress this view’s change');
    assert.equal(reloads, 1);
    assert.equal(firstChanges, 0, 'the saving view must still suppress its own change');
    secondView.fileAction('started');
    firstView.fileAction('cancelled');
    changed();
    assert.equal(secondChanges, 1, 'another view’s cancellation must not clear this view’s suppression');
    assert.equal(reloads, 2);
    secondView.fileAction('started');
    env.document.dispatchEvent(new CustomEvent('frontend-studio:component-file-action-cancelled'));
    changed();
    assert.equal(secondChanges, 1, 'unidentified actions must not change suppression');
    changed();
    assert.equal(secondChanges, 2);
    assert.equal(reloads, 5, 'later external changes must still reload both clean views');
    assert.deepEqual(ownChanges, [true, true, true, false], 'another controls scope must not cancel a pending own action');
  });
}

test('variant subscriptions deduplicate, filter changes and preserve dirty values without opening a stream', async () => {
  const root = element({ variantIdentifier: 'site:card:Default', componentChangeStreamUri: '/changes' });
  const env = environment([root]);
  let reloads = 0;
  env.window.location.reload = () => { reloads++; };
  class EventSource { constructor() { throw new Error('Variant documents must not open streams'); } }
  const modules = backendModules({ ...env, EventSource });
  const { default: View } = await modules.import('variant-view.js');
  const { getVariantState } = await modules.import('variant-state.js');
  await Promise.all([View.initialize(), View.initialize()]);
  assert.ok([...modules.loaded].every((name) => !name.endsWith('/variant-preview.js')));
  const view = getVariantState(root);
  assert.equal(view.features.size, 1);
  let changes = 0;
  view.addEventListener('files', () => { changes++; });
  const changed = (componentIdentifiers: string[]) => env.top.document.dispatchEvent(new CustomEvent('frontend-studio:component-files-changed', {
    detail: { componentIdentifiers },
  }));
  changed(['site:other']);
  env.top.document.dispatchEvent(new CustomEvent('frontend-studio:component-files-changed', { detail: { variantIdentifier: 'site:card:Copy' } }));
  assert.equal(changes, 0);
  view.hasUnsavedChanges = true;
  changed(['site:card']);
  assert.equal(changes, 1);
  assert.equal(reloads, 0);
  view.hasUnsavedChanges = false;
  changed(['site:card']);
  assert.equal(reloads, 1);
  env.window.dispatchEvent(new Event('pagehide'));
  changed(['site:card']);
  assert.equal(changes, 2);
  assert.equal(reloads, 1);
});

test('cached variant navigation suspends subscriptions and requests, then resumes only the active panel', async (t) => {
  const host = element({ variantIdentifier: 'site:card:Default', previewUri: '/preview', componentChangeStreamUri: '/changes' });
  const root = element({ activeTab: 'values' });
  root.closest = () => host;
  const buttons = ['values', 'html'].map((name) => element({ frontendStudioVariantTab: name }));
  const panels = ['values', 'html'].map((name) => element({ frontendStudioVariantTabPanel: name }));
  const container = element();
  panels[1].selectors.set('[data-frontend-studio-variant-html]', container);
  panels[1].selectors.set('[data-frontend-studio-variant-html-status]', element());
  root.selectors.set('[data-frontend-studio-variant-tab]', buttons);
  root.selectors.set('[data-frontend-studio-variant-tab-panel]', panels);
  const env = environment();
  env.document.querySelectorAll = (selector?: string) => selector?.endsWith('sidebar]') ? [root] : [host];
  const responses: { resolve: (response: any) => void; signal: AbortSignal }[] = [];
  const modules = backendModules({ ...env, fetch: (_url: string, options: { signal: AbortSignal }) =>
    new Promise((resolve) => responses.push({ resolve, signal: options.signal })) });
  const { default: Sidebar } = await modules.import('variant-sidebar.js');
  const { default: View } = await modules.import('variant-view.js');
  const { getVariantState } = await modules.import('variant-state.js');
  Sidebar.initialize();
  await View.initialize();
  const view = getVariantState(host);
  t.after(() => view.destroy());
  const sidebar = view.features.get(root);
  env.window.dispatchEvent(Object.assign(new Event('pageshow'), { persisted: false }));
  await sidebar.activateTab('html');
  const panel = sidebar.panels.get('html');
  const pending = panel.refresh();
  panel.schedule(10000);
  env.window.dispatchEvent(Object.assign(new Event('pagehide'), { persisted: true }));
  assert.equal(view.destroyed, false);
  assert.equal(responses[0].signal.aborted, true);
  responses[0].resolve({ ok: true, text: async () => 'Stale response while cached' });
  await pending;
  assert.equal(container.innerHTML, '');

  env.window.dispatchEvent(Object.assign(new Event('pageshow'), { persisted: true }));
  await new Promise((resolve) => setTimeout(resolve, 10));
  assert.equal(sidebar.activeTab, 'html');
  assert.equal(sidebar.panels.get('html'), panel);
  assert.equal(responses.length, 2);
  responses[1].resolve({ ok: true, text: async () => 'Refreshed after restoration' });
  await new Promise(setImmediate);
  assert.equal(container.innerHTML, 'Refreshed after restoration');
  let fileChanges = 0;
  view.hasUnsavedChanges = true;
  view.addEventListener('files', () => { fileChanges++; });
  const changed = () => env.top.document.dispatchEvent(new CustomEvent('frontend-studio:component-files-changed', {
    detail: { componentIdentifiers: ['site:card'] },
  }));
  view.changed('suspend');
  changed();
  assert.equal(fileChanges, 0);
  view.changed('resume');
  changed();
  assert.equal(fileChanges, 1);
  buttons[0].dispatchEvent(new Event('click'));
  assert.equal(panels[0].hidden, false);
  assert.equal(panels[1].hidden, true);

  env.window.dispatchEvent(Object.assign(new Event('pagehide'), { persisted: true }));
  env.window.dispatchEvent(Object.assign(new Event('pageshow'), { persisted: true }));
  await new Promise((resolve) => setTimeout(resolve, 10));
  assert.equal(responses.length, 2, 'hidden panels must stay idle after restoration');
  env.window.dispatchEvent(Object.assign(new Event('pagehide'), { persisted: false }));
  assert.equal(view.destroyed, true);
  changed();
  assert.equal(fileChanges, 1);
});

for (const embedded of [false, true]) {
  test(`header context selectors preserve supported languages and clear unavailable previews (embedded=${embedded})`, async (t) => {
    const root = element({
      variantIdentifier: 'site:card:Default', selectedSiteIdentifier: 'first', selectedLanguageHreflang: 'de',
    });
    const site = Object.assign(element(), { selectedOptions: [{ dataset: { languages: '' } }] });
    const language = Object.assign(element(), {
      options: [] as any[], replaceChildren(...options: any[]) { this.options = options; },
    });
    const link = Object.assign(element(), { href: '' });
    const iframe = element();
    root.selectors.set('[data-frontend-studio-site-select]', site);
    root.selectors.set('[data-frontend-studio-language-select]', language);
    root.selectors.set('[data-frontend-studio-open-rendered-variant]', link);
    root.selectors.set('[data-frontend-studio-variant-frame]', iframe);
    const env = environment([root]);
    const history = (context: any) => Object.assign(context, {
      history: { state: { preserved: true }, replaceState(state: any, _title: string, url: string) {
        assert.equal(state.preserved, true);
        context.location.href = url;
      } },
    });
    history(env.window);
    const outer = embedded ? history({ document: new EventTarget(), location: { href: 'https://example.test/typo3?keep=outer' } }) : env.window;
    const modules = backendModules({ ...env, top: outer, Option: class {
      text: string;
      value: string;
      constructor(text: string, value: string) { this.text = text; this.value = value; }
    } });
    const { default: Header } = await modules.import('variant-header.js');
    const { getVariantState } = await modules.import('variant-state.js');
    Header.initialize();
    Header.initialize();
    const view = getVariantState(root);
    t.after(() => view.destroy());
    assert.equal(view.features.size, 1);
    assert.equal(site.value, 'first');
    assert.equal(language.value, 'de');
    let changes = 0;
    view.addEventListener('context', () => { changes++; });
    site.value = 'second';
    site.selectedOptions[0].dataset.languages = JSON.stringify([
      { value: 'en', title: 'English' }, { value: 'de', title: 'Deutsch' },
    ]);
    site.dispatchEvent(new Event('change'));
    assert.equal(language.value, 'de');
    assert.equal(language.options.length, 2);
    assert.equal(language.disabled, false);
    assert.equal(changes, 1, 'reinitialization must not duplicate the selector listener');
    assert.equal(new URL(view.previewUri).searchParams.get('componentVariant'), 'site:card:Default');
    assert.equal(new URL(link.href).searchParams.get('site'), 'second');
    assert.equal(new URL(link.href).searchParams.get('language'), 'de');
    assert.equal(new URL(env.window.location.href).searchParams.get('componentVariant'), 'site:card:Default');
    if (embedded) assert.equal(new URL(outer.location.href).searchParams.get('keep'), 'outer');
    language.value = 'en';
    language.dispatchEvent(new Event('change'));
    assert.equal(new URL(view.previewUri).searchParams.get('language'), 'en');
    site.selectedOptions[0].dataset.languages = JSON.stringify([{ value: 'fr', title: 'French' }]);
    site.dispatchEvent(new Event('change'));
    assert.equal(language.value, 'fr', 'unsupported languages fall back to the first site language');
    site.selectedOptions[0].dataset.languages = 'invalid JSON';
    site.dispatchEvent(new Event('change'));
    assert.equal(language.disabled, true);
    assert.equal(language.options.length, 0);
    assert.equal(view.previewUri, '');
    assert.equal(iframe.src, 'about:blank');
    assert.equal(link.hidden, true);
    assert.equal(new URL(env.window.location.href).searchParams.has('language'), false);
    assert.equal(new URL(outer.location.href).searchParams.has('language'), false);
    view.destroy();
    const finalChanges = changes;
    language.dispatchEvent(new Event('change'));
    assert.equal(changes, finalChanges);
  });
}

for (const module of ['variant-header.js', 'variant-usage.js']) {
  test(`${module} copies visible text, reports clipboard errors and ignores completion after cleanup`, async (t) => {
    const root = element({ componentFilePath: 'EXT:site/Resources/Private/Card.fluid.html' });
    const code = element();
    const button = element({ frontendStudioCopyFluidUsage: 'inline' });
    root.selectors.set('[data-frontend-studio-copy-component-path]', button);
    root.selectors.set('[data-frontend-studio-copy-fluid-usage]', [button]);
    code.textContent = '<site:card title="A & B" />';
    root.selectors.set('[data-frontend-studio-fluid-usage-code="inline"]', code);
    root.selectors.set('[data-frontend-studio-fluid-usage-block]', []);
    const copied: string[] = [];
    const notifications: any[][] = [];
    let mode = 'success';
    let complete!: () => void;
    const modules = backendModules({ ...environment(), navigator: { clipboard: { writeText: (text: string) => {
      copied.push(text);
      if (mode === 'error') return Promise.reject(new Error('Clipboard unavailable'));
      if (mode === 'pending') return new Promise<void>((resolve) => { complete = resolve; });
      return Promise.resolve();
    } } } }, { '@typo3/backend/notification.js': {
      success: (...args: any[]) => notifications.push(['success', ...args]),
      error: (...args: any[]) => notifications.push(['error', ...args]),
    } });
    const { VariantState } = await modules.import('variant-state.js');
    const { default: Feature } = await modules.import(module);
    const view = new VariantState(root);
    const feature = view.mount(root, () => new Feature(root, view));
    t.after(() => view.destroy());
    const copy = () => module === 'variant-header.js' ? feature.copyComponentFilePath() : feature.copy('inline');
    const expected = module === 'variant-header.js' ? root.dataset.componentFilePath : code.textContent;
    button.dispatchEvent(new Event('click'));
    await new Promise(setImmediate);
    assert.equal(copied[0], expected);
    assert.equal(notifications[0][0], 'success');
    mode = 'error';
    await copy();
    assert.deepEqual(notifications[1], ['error', 'Copy failed', 'Clipboard unavailable']);
    feature.componentFilePath = '';
    code.textContent = '';
    await copy();
    assert.equal(copied.length, 2, 'empty paths and snippets must not overwrite the clipboard');
    feature.componentFilePath = expected;
    code.textContent = expected;
    mode = 'pending';
    const pending = copy();
    view.destroy();
    complete();
    await pending;
    assert.equal(notifications.length, 2);
  });
}

test('Fluid Usage renders both syntaxes, hides missing snippets and retries malformed responses', async (t) => {
  const root = element();
  const blocks = ['inline', 'tag'].map((name) => element({ frontendStudioFluidUsageBlock: name }));
  const codes = blocks.map(() => element());
  blocks.forEach((block, index) => block.selectors.set('[data-frontend-studio-fluid-usage-code]', codes[index]));
  root.selectors.set('[data-frontend-studio-fluid-usage-block]', blocks);
  const notifications: string[] = [];
  let source = JSON.stringify({ inline: '<span>Inline usage</span>', tag: '<span>Tag usage</span>' });
  let requests = 0;
  const modules = backendModules({ ...environment(), fetch: async (url: string, options: any) => {
    assert.equal(new URL(url).searchParams.get('frontendStudioPreviewFormat'), 'fluid-usage');
    assert.equal(options.credentials, 'same-origin');
    assert.equal(options.headers['X-Requested-With'], 'XMLHttpRequest');
    requests++;
    return { ok: true, text: async () => source };
  } }, { '@typo3/backend/notification.js': { error: (_title: string, message: string) => notifications.push(message) } });
  const { VariantState } = await modules.import('variant-state.js');
  const { default: Usage } = await modules.import('variant-usage.js');
  const view = new VariantState(element({ previewUri: '/preview' }));
  t.after(() => view.destroy());
  const panel = view.mount(root, () => new Usage(root, view));
  await panel.refresh();
  assert.equal(codes[0].innerHTML, '<span>Inline usage</span>');
  assert.equal(codes[1].innerHTML, '<span>Tag usage</span>');
  assert.equal(blocks[1].hidden, false);
  await panel.refresh();
  assert.equal(requests, 1, 'unchanged previews reuse the rendered snippets');
  source = 'invalid JSON';
  panel.invalidate();
  await panel.refresh();
  assert.equal(notifications.length, 1);
  source = JSON.stringify({ inline: '<span>Updated usage</span>' });
  await panel.refresh();
  assert.equal(requests, 3);
  assert.equal(codes[0].innerHTML, '<span>Updated usage</span>');
  assert.equal(codes[1].innerHTML, '');
  assert.equal(blocks[1].hidden, true);
});

for (const action of ['save', 'copy']) {
  for (const failure of ['payload', 'missing variant', 'HTTP', 'network']) {
    test(`${action} ${failure} failure preserves edits and Reset baseline, clears suppression and allows retry`, async (t) => {
      const { root, field, reset, save, copy } = controlsRoot();
      const env = environment([root]);
      const originalUrl = env.window.location.href;
      const notifications: any[][] = [];
      const events: string[] = [];
      let requests = 0;
      let fail = true;
      env.document.addEventListener('frontend-studio:component-file-action-cancelled', (event) => {
        events.push((event as CustomEvent).detail.action);
      });
      class AjaxRequest {
        async post() {
          requests++;
          if (!fail) return { resolve: async () => ({ success: true, variant: { identifier: 'site:card:New' } }) };
          if (failure === 'HTTP') throw { resolve: async () => ({ message: 'Fixture is read-only' }) };
          if (failure === 'network') throw new Error('Connection lost');
          return { resolve: async () => failure === 'payload'
            ? { success: false, message: 'Fixture is read-only' } : { success: true } };
        }
      }
      const modules = backendModules(env, {
        '@typo3/core/ajax/ajax-request.js': AjaxRequest,
        '@typo3/backend/notification.js': {
          success: (...args: any[]) => notifications.push(['success', ...args]),
          error: (...args: any[]) => notifications.push(['error', ...args]),
        },
      });
      const { default: Controls } = await modules.import('variant-controls.js');
      const { getVariantState } = await modules.import('variant-state.js');
      Controls.initialize();
      const view = getVariantState(root);
      t.after(() => view.destroy());
      field.value = 'Unsaved edit';
      field.dispatchEvent(new Event('input'));
      const write = () => action === 'save' ? view.controls.saveValues() : view.controls.copyVariant('New');
      await write();
      assert.equal(view.hasUnsavedChanges, true);
      assert.equal(field.value, 'Unsaved edit');
      assert.equal(view.ignoreNextComponentFilesChanged, false);
      assert.equal(save.disabled, false);
      assert.equal(copy.disabled, false);
      assert.equal(env.window.location.href, originalUrl);
      assert.deepEqual(events, [action]);
      assert.equal(notifications[0][0], 'error');
      assert.equal(notifications[0][2], failure === 'network' ? 'Connection lost'
        : failure === 'missing variant' ? (action === 'save' ? 'The variant values could not be saved.' : 'The variant could not be copied.')
          : 'Fixture is read-only');
      reset.dispatchEvent(new Event('click'));
      assert.equal(field.value, 'Saved');
      field.value = 'Retried edit';
      field.dispatchEvent(new Event('input'));
      fail = false;
      await write();
      assert.equal(requests, 2);
      assert.equal(notifications[1][0], 'success');
      if (action === 'save') {
        assert.equal(view.hasUnsavedChanges, false);
      } else {
        assert.equal(new URL(env.window.location.href).searchParams.get('componentVariant'), 'site:card:New');
      }
    });
  }
}

for (const invalid of ['value', 'slot']) {
  test(`invalid ${invalid} blocks Save and Save as new before starting a file action`, async (t) => {
    const { root, field, save } = controlsRoot();
    const slot = element({ slotName: 'default' });
    root.selectors.set('[data-frontend-studio-variant-slot]', [slot]);
    const invalidField = invalid === 'value' ? field : slot;
    invalidField.checkValidity = () => false;
    invalidField.reportValidity = () => false;
    let focused = 0;
    invalidField.focus = () => { focused++; };
    const env = environment([root]);
    let actions = 0;
    env.document.addEventListener('frontend-studio:component-file-action-started', () => { actions++; });
    const modules = backendModules(env);
    const { default: Controls } = await modules.import('variant-controls.js');
    const { getVariantState } = await modules.import('variant-state.js');
    Controls.initialize();
    const view = getVariantState(root);
    t.after(() => view.destroy());
    field.value = 'Edited';
    field.dispatchEvent(new Event('input'));
    assert.equal(save.attributes.get('aria-disabled'), 'true');
    assert.equal(save.classList.contains('frontend-studio-variant-save-invalid'), true);
    await view.controls.saveValues();
    await view.controls.copyVariant('New');
    assert.equal(actions, 0);
    assert.equal(focused, 1);
    assert.equal(view.hasUnsavedChanges, true);
    assert.equal(view.ignoreNextComponentFilesChanged, false);
  });
}

test('Save as new handles prompt cancellation, snapshots slots, deduplicates clicks and preserves context when navigating', async (t) => {
  const { root, field, copy } = controlsRoot();
  const slot = element({ slotName: 'default' });
  slot.value = '<strong>Slot edit</strong>';
  root.selectors.set('[data-frontend-studio-variant-slot]', [slot]);
  const env = environment([root]);
  env.window.location.href += '&site=preview&language=de&keep=yes';
  let name: string | null = null;
  let promptCount = 0;
  Object.assign(env.window, { prompt: (_message: string, suggestion: string) => {
    assert.equal(suggestion, 'Default copy');
    promptCount++;
    return name;
  } });
  const requests: any[] = [];
  let resolve!: (response: any) => void;
  const changed: string[] = [];
  env.document.addEventListener('frontend-studio:component-files-changed', (event) => {
    changed.push((event as CustomEvent).detail.variantIdentifier);
  });
  const modules = backendModules(env, { '@typo3/core/ajax/ajax-request.js': class {
    constructor(url: string) { assert.equal(url, '/copy'); }
    post(payload: any) {
      requests.push(payload);
      return new Promise((done) => { resolve = done; });
    }
  } });
  const { default: Controls } = await modules.import('variant-controls.js');
  const { getVariantState } = await modules.import('variant-state.js');
  Controls.initialize();
  const view = getVariantState(root);
  t.after(() => view.destroy());
  copy.dispatchEvent(new Event('click'));
  assert.equal(promptCount, 1);
  assert.equal(requests.length, 0);
  assert.equal(view.ignoreNextComponentFilesChanged, false);
  name = 'New variant';
  field.value = 'Copied title';
  copy.dispatchEvent(new Event('click'));
  assert.equal(copy.disabled, true);
  await view.controls.copyVariant('Duplicate');
  assert.equal(requests.length, 1);
  assert.equal(requests[0].name, 'New variant');
  assert.equal(requests[0].values.title, 'Copied title');
  assert.equal(requests[0].slots.default, '<strong>Slot edit</strong>');
  field.value = 'Later edit';
  slot.value = 'Later slot';
  assert.equal(requests[0].values.title, 'Copied title');
  assert.equal(requests[0].slots.default, '<strong>Slot edit</strong>');
  resolve({ resolve: async () => ({ success: true, variant: { identifier: 'site:card:New variant' } }) });
  await new Promise(setImmediate);
  assert.deepEqual(changed, ['site:card:New variant']);
  const url = new URL(env.window.location.href);
  assert.equal(url.searchParams.get('componentVariant'), 'site:card:New variant');
  assert.equal(url.searchParams.get('site'), 'preview');
  assert.equal(url.searchParams.get('language'), 'de');
  assert.equal(url.searchParams.get('keep'), 'yes');
});

test('cleanup aborts a pending copy and prevents late notifications, tree events and navigation', async () => {
  const { root } = controlsRoot();
  const env = environment([root]);
  const url = env.window.location.href;
  let signal!: AbortSignal;
  let resolve!: (response: any) => void;
  let notifications = 0;
  let treeEvents = 0;
  env.document.addEventListener('frontend-studio:component-files-changed', () => { treeEvents++; });
  const modules = backendModules(env, {
    '@typo3/core/ajax/ajax-request.js': class {
      post(_payload: any, options: { signal: AbortSignal }) {
        signal = options.signal;
        return new Promise((done) => { resolve = done; });
      }
    },
    '@typo3/backend/notification.js': { success: () => { notifications++; }, error: () => { notifications++; } },
  });
  const { default: Controls } = await modules.import('variant-controls.js');
  const { getVariantState } = await modules.import('variant-state.js');
  Controls.initialize();
  const view = getVariantState(root);
  const pending = view.controls.copyVariant('New');
  view.destroy();
  assert.equal(signal.aborted, true);
  resolve({ resolve: async () => ({ success: true, variant: { identifier: 'site:card:New' } }) });
  await pending;
  assert.equal(notifications, 0);
  assert.equal(treeEvents, 0);
  assert.equal(env.window.location.href, url);
});

test('watcher ignores malformed and unrelated events, refreshes dirty views and reloads only clean affected views', async (t) => {
  const root = element({ variantIdentifier: 'site:card:Default', componentChangeStreamUri: '/changes' });
  const env = environment();
  let reloads = 0;
  let files = 0;
  let treeEvents = 0;
  env.window.location.reload = () => { reloads++; };
  env.document.addEventListener('frontend-studio:component-files-changed', () => { treeEvents++; });
  const modules = backendModules({ ...env, EventSource: class extends EventTarget { close() {} } });
  const { default: Owner } = await modules.import('component-file-watcher.js');
  const owner = new Owner();
  t.after(() => owner.destroy());
  owner.updateModule({ module: 'admin_frontendstudio', componentChangeStreamUri: '/changes' });
  const { VariantState } = await modules.import('variant-state.js');
  const { default: Watcher } = await modules.import('variant-file-watcher.js');
  const view = new VariantState(root);
  view.mount(root, () => new Watcher(root, view));
  t.after(() => view.destroy());
  view.addEventListener('files', () => { files++; });
  const change = (data: string) => owner.source.dispatchEvent(Object.assign(new Event('component-files-changed'), { data }));
  for (const data of ['invalid JSON', '{}', '{"componentIdentifiers":"site:card"}', '{"componentIdentifiers":["site:other"]}']) change(data);
  assert.equal(files, 0);
  assert.equal(reloads, 0);
  assert.equal(treeEvents, 1, 'only valid changes reach the tree, including unrelated components');
  view.hasUnsavedChanges = true;
  change('{"componentIdentifiers":["site:card"]}');
  assert.equal(files, 1);
  assert.equal(reloads, 0, 'external changes must preserve unsaved edits');
  view.hasUnsavedChanges = false;
  change('{"componentIdentifiers":["site:card"]}');
  assert.equal(files, 2);
  assert.equal(reloads, 1);
});

test('Save and Reset retain submitted slots and nullable/default baselines while later edits stay dirty', async (t) => {
  const { root, field, reset } = controlsRoot();
  root.dataset.previewUri = '/preview';
  const nullable = element({ fixtureName: 'subtitle', fixtureType: 'string', fixtureValueNull: 'true' });
  const slot = element({ slotName: 'default' });
  slot.value = '<p>Saved slot</p>';
  root.selectors.set('[data-frontend-studio-variant-value]', [field, nullable]);
  root.selectors.set('[data-frontend-studio-variant-slot]', [slot]);
  const requests: any[] = [];
  let resolve!: (response: any) => void;
  const modules = backendModules(environment([root]), { '@typo3/core/ajax/ajax-request.js': class {
    post(payload: any) {
      requests.push(payload);
      return new Promise((done) => { resolve = done; });
    }
  } });
  const { default: Controls } = await modules.import('variant-controls.js');
  const { getVariantState } = await modules.import('variant-state.js');
  Controls.initialize();
  const view = getVariantState(root);
  t.after(() => view.destroy());
  slot.value = '<p>Submitted slot</p>';
  nullable.value = 'Submitted subtitle';
  slot.dispatchEvent(new Event('input'));
  const pending = view.controls.saveValues();
  assert.equal(requests[0].slots.default, '<p>Submitted slot</p>');
  assert.equal(requests[0].values.subtitle, 'Submitted subtitle');
  slot.value = '<p>Later slot</p>';
  nullable.value = 'Later subtitle';
  slot.dispatchEvent(new Event('input'));
  resolve({ resolve: async () => ({ success: true, variant: {} }) });
  await pending;
  assert.equal(view.hasUnsavedChanges, true);
  assert.equal(nullable.dataset.fixtureValueDefined, 'true');
  reset.dispatchEvent(new Event('click'));
  assert.equal(slot.value, '<p>Submitted slot</p>');
  assert.equal(nullable.value, 'Submitted subtitle');
  assert.equal(view.hasUnsavedChanges, false);
  const preview = view.buildPreviewUrl();
  assert.equal(JSON.parse(preview.searchParams.get('componentVariantSlots')).default, '<p>Submitted slot</p>');
  assert.equal(JSON.parse(preview.searchParams.get('componentVariantValues')).subtitle, 'Submitted subtitle');
  nullable.value = '';
  nullable.dispatchEvent(new Event('input'));
  assert.equal(view.controls.collectValues().subtitle, '', 'clearing a saved string must not restore its old null marker');
});

test('removing a view destroys its features and prevents pending lazy imports from mounting', async () => {
  const { root, field } = controlsRoot();
  root.dataset.previewUri = '/preview';
  root.selectors.set('[data-frontend-studio-variant-frame]', element());
  let changed!: () => void;
  let disconnected = 0;
  const modules = backendModules({ ...environment([root]), MutationObserver: class {
    constructor(callback: () => void) { changed = callback; }
    observe() {}
    disconnect() { disconnected++; }
  } });
  const { default: Controls } = await modules.import('variant-controls.js');
  const { default: View } = await modules.import('variant-view.js');
  const { getVariantState } = await modules.import('variant-state.js');
  Controls.initialize();
  const view = getVariantState(root);
  const pending = View.initialize();
  assert.equal(view.features.has('preview'), false);
  root.isConnected = false;
  changed();
  await pending;
  assert.equal(view.destroyed, true);
  assert.equal(view.features.size, 0);
  assert.equal(disconnected, 1);
  const revision = view.revision;
  field.dispatchEvent(new Event('input'));
  view.changed('context');
  assert.equal(view.revision, revision);
});

test('rapid tab switching avoids hidden panel requests and remains usable when tab persistence fails', async (t) => {
  const root = element({ activeTab: 'values', previewUri: '/preview' });
  const names = ['values', 'html', 'usage'];
  const buttons = names.map((name) => element({ frontendStudioVariantTab: name }));
  const panels = names.map((name) => element({ frontendStudioVariantTabPanel: name }));
  panels[1].selectors.set('[data-frontend-studio-variant-html]', element());
  panels[1].selectors.set('[data-frontend-studio-variant-html-status]', element());
  panels[2].selectors.set('[data-frontend-studio-fluid-usage-block]', []);
  root.selectors.set('[data-frontend-studio-variant-tab]', buttons);
  root.selectors.set('[data-frontend-studio-variant-tab-panel]', panels);
  const persisted: string[] = [];
  let requests = 0;
  const timers = new Map<number, () => void>();
  let timerId = 0;
  const env = environment([root]);
  Object.assign(env.window, {
    setTimeout: (callback: () => void) => { timers.set(++timerId, callback); return timerId; },
    clearTimeout: (id: number) => timers.delete(id),
  });
  const modules = backendModules({ ...env, fetch: async () => {
    requests++;
    return { ok: true, text: async () => 'Rendered HTML' };
  } }, { '@typo3/backend/storage/persistent.js': { set: async (_key: string, value: string) => {
    persisted.push(value);
    throw new Error('Storage unavailable');
  } } });
  const { default: Sidebar } = await modules.import('variant-sidebar.js');
  const { getVariantState } = await modules.import('variant-state.js');
  Sidebar.initialize();
  const view = getVariantState(root);
  t.after(() => view.destroy());
  const sidebar = view.features.get(root);
  const opening = sidebar.activateTab('html');
  await sidebar.activateTab('values');
  await opening;
  assert.equal(sidebar.panels.size, 0);
  assert.equal(timers.size, 0);
  assert.equal(requests, 0);
  assert.equal(buttons[0].attributes.get('aria-selected'), 'true');
  assert.equal(panels[0].hidden, false);
  assert.equal(panels[1].hidden, true);
  await sidebar.activateTab('html');
  assert.equal(timers.size, 1);
  const panel = sidebar.panels.get('html');
  view.changed('values');
  view.changed('values');
  assert.equal(timers.size, 1, 'successive edits debounce into one active-panel request');
  await sidebar.activateTab('usage');
  assert.equal(panel.previewUrl, '');
  assert.equal(timers.size, 1, 'switching tabs cancels the old panel timer');
  await sidebar.activateTab('unknown');
  assert.equal(sidebar.activeTab, 'values');
  assert.equal(timers.size, 0);
  assert.ok(persisted.includes('html'));
  assert.equal(persisted.at(-1), 'values');
});

test('switching away from a loading panel aborts its request and discards a late response', async (t) => {
  const root = element({ activeTab: 'values', previewUri: '/preview' });
  const names = ['values', 'html'];
  const buttons = names.map((name) => element({ frontendStudioVariantTab: name }));
  const panels = names.map((name) => element({ frontendStudioVariantTabPanel: name }));
  const container = element();
  panels[1].selectors.set('[data-frontend-studio-variant-html]', container);
  panels[1].selectors.set('[data-frontend-studio-variant-html-status]', element());
  root.selectors.set('[data-frontend-studio-variant-tab]', buttons);
  root.selectors.set('[data-frontend-studio-variant-tab-panel]', panels);
  const env = environment([root]);
  const requests: { signal: AbortSignal; resolve: (response: any) => void }[] = [];
  const modules = backendModules({ ...env, fetch: (_url: string, options: { signal: AbortSignal }) =>
    new Promise((resolve) => requests.push({ resolve, signal: options.signal })) });
  const { default: Sidebar } = await modules.import('variant-sidebar.js');
  const { getVariantState } = await modules.import('variant-state.js');
  Sidebar.initialize();
  const view = getVariantState(root);
  t.after(() => view.destroy());
  const sidebar = view.features.get(root);
  await sidebar.activateTab('html');
  const panel = sidebar.panels.get('html');
  env.window.clearTimeout(panel.timeout);
  const first = panel.refresh();
  assert.equal(requests.length, 1);
  await sidebar.activateTab('values');
  assert.equal(requests[0].signal.aborted, true);
  requests[0].resolve({ ok: true, text: async () => 'Late hidden HTML' });
  await first;
  assert.equal(container.innerHTML, '');
  await sidebar.activateTab('html');
  env.window.clearTimeout(panel.timeout);
  const second = panel.refresh();
  assert.equal(requests.length, 2, 'returning to the panel requests a fresh preview');
  requests[1].resolve({ ok: true, text: async () => 'Fresh active HTML' });
  await second;
  assert.equal(container.innerHTML, 'Fresh active HTML');

});

for (const action of ['create', 'copy', 'delete']) {
  for (const sameComponent of [true, false]) {
    test(`tree ${action} before watcher import suppresses only its component (matching=${sameComponent})`, async (t) => {
      const root = element({ variantIdentifier: 'site:card:Default', componentChangeStreamUri: '/changes' });
      const env = environment([root]);
      let reloads = 0;
      let changes = 0;
      env.window.location.reload = () => { reloads++; };
      const modules = backendModules({ ...env, EventSource: class extends EventTarget { close() {} } });
      const { default: Owner } = await modules.import('component-file-watcher.js');
      const owner = new Owner();
      t.after(() => owner.destroy());
      owner.updateModule({ module: 'admin_frontendstudio', componentChangeStreamUri: '/changes' });
      const { default: View } = await modules.import('variant-view.js');
      const { getVariantState } = await modules.import('variant-state.js');
      const view = getVariantState(root);
      t.after(() => view.destroy());
      view.addEventListener('files', () => { changes++; });
      const component = sameComponent ? 'site:card' : 'site:cardOther';
      const identifier = action === 'create' ? component : `${component}:Other`;
      const pending = View.initialize();
      assert.equal(view.features.has('watcher'), false);
      env.document.dispatchEvent(new CustomEvent('frontend-studio:component-file-action-started', {
        detail: { action, identifier },
      }));
      await pending;
      const source = owner.source;
      const changed = () => source.dispatchEvent(Object.assign(new Event('component-files-changed'), {
        data: JSON.stringify({ componentIdentifiers: ['site:card'] }),
      }));
      changed();
      assert.equal(reloads, sameComponent ? 0 : 1);
      assert.equal(changes, sameComponent ? 0 : 1);
      assert.equal(view.ignoreNextComponentFilesChanged, false);
      changed();
      assert.equal(reloads, sameComponent ? 1 : 2, 'later external changes must reload normally');
    });
  }

  test(`tree ${action} cancellation restores watching only for the matching component`, async (t) => {
    const root = element({ variantIdentifier: 'site:card:Default', componentChangeStreamUri: '/changes' });
    const env = environment([root]);
    let reloads = 0;
    env.window.location.reload = () => { reloads++; };
    const modules = backendModules({ ...env, EventSource: class extends EventTarget { close() {} } });
    const { default: Owner } = await modules.import('component-file-watcher.js');
    const owner = new Owner();
    t.after(() => owner.destroy());
    owner.updateModule({ module: 'admin_frontendstudio', componentChangeStreamUri: '/changes' });
    const { default: View } = await modules.import('variant-view.js');
    const { getVariantState } = await modules.import('variant-state.js');
    await View.initialize();
    const view = getVariantState(root);
    t.after(() => view.destroy());
    const identifier = action === 'create' ? 'site:card' : 'site:card:Other';
    const dispatch = (type: string, identifier: string) => env.document.dispatchEvent(new CustomEvent(
      `frontend-studio:component-file-action-${type}`, { detail: { action, identifier } },
    ));
    const changed = () => owner.source.dispatchEvent(Object.assign(new Event('component-files-changed'), {
      data: JSON.stringify({ componentIdentifiers: ['site:card'] }),
    }));
    dispatch('started', identifier);
    dispatch('cancelled', 'site:cardOther:Default');
    assert.equal(view.ignoreNextComponentFilesChanged, true, 'another component’s cancellation must not clear suppression');
    changed();
    assert.equal(reloads, 0);
    dispatch('started', identifier);
    dispatch('cancelled', identifier);
    changed();
    assert.equal(reloads, 1, 'the cancelled action must not swallow an external change');
  });
}

test('tree listeners ignore controls and unidentified events and are removed with the state', async () => {
  const root = element({ variantIdentifier: 'site:card:Default' });
  const env = environment();
  const { VariantState } = await backendModules(env).import('variant-state.js');
  const view = new VariantState(root);
  for (const detail of [undefined, {}, { identifier: 42 }, { identifier: '' }, { variantIdentifier: 'site:card:Default' },
    { identifier: 'site:card', variantIdentifier: 'site:card:Default' }]) {
    env.document.dispatchEvent(new CustomEvent('frontend-studio:component-file-action-started', { detail }));
    assert.equal(view.ignoreNextComponentFilesChanged, false);
  }
  env.document.dispatchEvent(new CustomEvent('frontend-studio:component-file-action-started', {
    detail: { action: 'create', identifier: 'site:card' },
  }));
  assert.equal(view.ignoreNextComponentFilesChanged, true);
  env.document.dispatchEvent(new CustomEvent('frontend-studio:component-file-action-cancelled', {
    detail: { variantIdentifier: 'site:card:Default' },
  }));
  assert.equal(view.ignoreNextComponentFilesChanged, true, 'a controls cancellation must not cancel a tree action');
  view.destroy();
  env.document.dispatchEvent(new CustomEvent('frontend-studio:component-file-action-cancelled', {
    detail: { action: 'create', identifier: 'site:card' },
  }));
  assert.equal(view.ignoreNextComponentFilesChanged, true, 'destroyed states must have no global listeners');
});

for (const action of ['save', 'copy', 'tree-copy']) {
  test(`${action} suppression survives unrelated SSE until its matching write event`, async (t) => {
    const { root, field } = controlsRoot();
    root.dataset.componentChangeStreamUri = '/changes';
    const env = environment([root]);
    let reloads = 0;
    let files = 0;
    let treeEvents = 0;
    let resolve!: (response: any) => void;
    env.window.location.reload = () => { reloads++; };
    env.document.addEventListener('frontend-studio:component-files-changed', (event) => {
      if (!(event as CustomEvent).detail.ownAction) { treeEvents++; }
    });
    const modules = backendModules({ ...env, EventSource: class extends EventTarget { close() {} } }, {
      '@typo3/core/ajax/ajax-request.js': class {
        post() { return new Promise((done) => { resolve = done; }); }
      },
    });
    const { default: Owner } = await modules.import('component-file-watcher.js');
    const owner = new Owner();
    t.after(() => owner.destroy());
    owner.updateModule({ module: 'admin_frontendstudio', componentChangeStreamUri: '/changes' });
    const { default: Controls } = await modules.import('variant-controls.js');
    const { default: View } = await modules.import('variant-view.js');
    const { getVariantState } = await modules.import('variant-state.js');
    Controls.initialize();
    await View.initialize();
    const view = getVariantState(root);
    t.after(() => view.destroy());
    view.addEventListener('files', () => { files++; });
    let pending: Promise<void> | undefined;
    if (action === 'save') {
      field.value = 'Submitted';
      field.dispatchEvent(new Event('input'));
      pending = view.controls.saveValues();
    } else if (action === 'copy') {
      pending = view.controls.copyVariant('New');
    } else {
      env.document.dispatchEvent(new CustomEvent('frontend-studio:component-file-action-started', {
        detail: { action: 'copy', identifier: 'site:card:Other' },
      }));
    }
    const changed = (data: string) => owner.source.dispatchEvent(Object.assign(
      new Event('component-files-changed'), { data },
    ));
    for (const data of ['{"componentIdentifiers":["site:other"]}', 'invalid JSON', '{}']) {
      changed(data);
      assert.equal(view.ignoreNextComponentFilesChanged, true, 'unrelated events must preserve suppression for the pending write');
    }
    assert.equal(treeEvents, 1, 'valid unrelated events must still notify the component tree');
    assert.equal(files, 0);
    assert.equal(reloads, 0);
    if (action === 'save') {
      resolve({ resolve: async () => ({ success: true, variant: {} }) });
      await pending;
    }
    assert.equal(view.hasUnsavedChanges, false);
    changed('{"componentIdentifiers":["site:card"]}');
    assert.equal(view.ignoreNextComponentFilesChanged, false, 'only the matching event consumes suppression');
    assert.equal(treeEvents, 1);
    assert.equal(files, 0);
    assert.equal(reloads, 0, 'the own write must not reload a clean view or compete with copy navigation');
    if (action === 'copy') {
      resolve({ resolve: async () => ({ success: true, variant: { identifier: 'site:card:New' } }) });
      await pending;
      assert.equal(new URL(env.window.location.href).searchParams.get('componentVariant'), 'site:card:New');
    }
    changed('{"componentIdentifiers":["site:card"]}');
    assert.equal(files, 1);
    assert.equal(reloads, 1, 'later matching external changes must reload normally');
  });
}
