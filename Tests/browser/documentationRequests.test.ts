import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createContext, SourceTextModule, SyntheticModule } from 'node:vm';
import test from 'node:test';

async function setup({ deferEditor = false, readOnly = false, initialMarkdown = 'Original\n' } = {}) {
  const elements = new Map<string, any>();
  for (const name of ['rich', 'status', ...(!readOnly ? ['save', 'reset', 'save-state', 'actions'] : [])]) {
    elements.set(`[data-doc-${name}]`, Object.assign(new EventTarget(), { textContent: '', innerHTML: '', hidden: false, disabled: false }));
  }
  const root = Object.assign(new EventTarget(), { dataset: { docUri: '/docs', componentIdentifier: 'site:card' }, querySelector: (name: string) => elements.get(name) ?? null });
  const view = Object.assign(new EventTarget(), { documentationDirty: false });
  const pending: Array<(value: unknown) => void> = [];
  const bodies: any[] = [];
  const requests: any[] = [];
  const notifications: string[] = [];
  const window = Object.assign(new EventTarget(), { location: { href: 'https://example.test' }, confirm: (): boolean => true });
  const document = new EventTarget();
  const context = createContext({
    Event, EventTarget, AbortController, URL, URLSearchParams, window, top: { document },
    fetch: async (_url: URL, options: any) => {
      requests.push({ url: _url, ...options });
      bodies.push(options.body ? Object.fromEntries(new URLSearchParams(options.body)) : null);
      return new Promise(resolve => pending.push(resolve));
    },
  });
  const lifecycle = new SourceTextModule(await readFile(new URL('../../Resources/Public/JavaScript/Backend/variant-lifecycle.js', import.meta.url), 'utf8'), { context });
  await lifecycle.link(() => { throw new Error('Unexpected import'); });
  const editors: any[] = [];
  const dependencies = new SyntheticModule(['createEditor', 'renderMarkdown', 'default'], function () {
    this.setExport('createEditor', (_root: unknown, source: string, change: (markdown: string) => void) => new Promise((resolve, reject) => {
      const editor = { destroyed: 0, data: source,
        destroy: async () => { editor.destroyed++; },
        setData: (data: string) => { editor.data = data; change(data.trim()); },
      };
      editors.push({ resolve: () => resolve(editor), reject, change, editor });
    }));
    this.setExport('renderMarkdown', (source: string) => `<p>${source.trim()}</p>`);
    this.setExport('default', { success: () => notifications.push('success'), error: () => notifications.push('error') });
  }, { context });
  await dependencies.link(() => { throw new Error('Unexpected import'); });
  const module = new SourceTextModule(await readFile(new URL('../../Resources/Public/JavaScript/Backend/component-documentation.js', import.meta.url), 'utf8'), { context });
  await module.link(specifier => specifier.endsWith('variant-lifecycle.js') ? lifecycle : dependencies);
  await module.evaluate();
  const Documentation = (module.namespace as any).default;
  const doc = new Documentation(root, view);
  const tick = () => new Promise(setImmediate);
  const respond = async (body: unknown, ok = true) => {
    pending.shift()!({ ok, json: async () => body });
    await tick();
  };
  await respond({ markdown: initialMarkdown, revision: 'r1', readOnly });
  if (!deferEditor && !readOnly) { editors[0].resolve(); await tick(); }
  return { doc, root, view, bodies, requests, respond, editors, elements, notifications, tick, window, document, status: elements.get('[data-doc-status]'),
    edit: (markdown: string) => editors.at(-1).change(markdown) };
}

test('approved tree navigation prompts once while cancelled navigation retains unload protection and unsaved Markdown', async (t) => {
  const ui = await setup(); t.after(() => ui.doc.destroy());
  let confirmations = 0;
  let approve = false;
  ui.window.confirm = () => { confirmations++; return approve; };
  ui.edit('Unsaved documentation');
  const unload = () => {
    const event = new Event('beforeunload', { cancelable: true });
    ui.window.dispatchEvent(event);
    return event.defaultPrevented;
  };
  assert.equal(ui.document.dispatchEvent(new Event('frontend-studio:before-navigate', { cancelable: true })), false);
  assert.equal(unload(), true);
  assert.equal(ui.doc.markdown, 'Unsaved documentation');
  approve = true;
  assert.equal(ui.document.dispatchEvent(new Event('frontend-studio:before-navigate', { cancelable: true })), true);
  assert.equal(unload(), false, 'approved navigation must not display a second prompt');
  assert.equal(unload(), true, 'approval only applies to the next unload');
  assert.equal(confirmations, 2);
  assert.equal(ui.doc.dirty, true);
  assert.equal(ui.view.documentationDirty, true);
  assert.equal(ui.doc.baseline, 'Original\n');
});

test('navigation cancelled by another guard and edits after approval retain unload protection', async (t) => {
  const ui = await setup(); t.after(() => ui.doc.destroy());
  ui.edit('Unsaved documentation');
  const cancel = (event: Event) => event.preventDefault();
  ui.document.addEventListener('frontend-studio:before-navigate', cancel);
  assert.equal(ui.document.dispatchEvent(new Event('frontend-studio:before-navigate', { cancelable: true })), false);
  let unload = new Event('beforeunload', { cancelable: true });
  ui.window.dispatchEvent(unload);
  assert.equal(unload.defaultPrevented, true);
  ui.document.removeEventListener('frontend-studio:before-navigate', cancel);
  ui.document.dispatchEvent(new Event('frontend-studio:before-navigate', { cancelable: true }));
  ui.edit('New edits after approval');
  unload = new Event('beforeunload', { cancelable: true });
  ui.window.dispatchEvent(unload);
  assert.equal(unload.defaultPrevented, true);
  assert.equal(ui.doc.markdown, 'New edits after approval');
});

test('the editor always opens without modifying original Markdown and exposes Controls-style dirty actions', async (t) => {
  const ui = await setup(); t.after(() => ui.doc.destroy());
  assert.ok(ui.doc.editor);
  assert.equal(ui.doc.markdown, 'Original\n');
  assert.equal(ui.doc.dirty, false);
  assert.equal(ui.elements.get('[data-doc-rich]').hidden, false);
  assert.equal(ui.elements.get('[data-doc-save]').disabled, true);
  assert.equal(ui.elements.get('[data-doc-reset]').disabled, true);
  assert.equal(ui.elements.get('[data-doc-save-state]').hidden, true);
  ui.edit('Local edits');
  assert.equal(ui.elements.get('[data-doc-save]').disabled, false);
  assert.equal(ui.elements.get('[data-doc-reset]').disabled, false);
  assert.equal(ui.elements.get('[data-doc-save-state]').hidden, false);
  ui.elements.get('[data-doc-reset]').dispatchEvent(new Event('click'));
  assert.equal(ui.doc.markdown, 'Original\n');
  assert.equal(ui.doc.editor.data, 'Original\n');
  assert.equal(ui.doc.dirty, false);
  assert.equal(ui.view.documentationDirty, false);
  assert.equal(ui.bodies.length, 1, 'Reset must not reload from disk');
});

test('a late reload preserves documentation typed while the request was pending', async (t) => {
  const ui = await setup(); t.after(() => ui.doc.destroy());
  const load = ui.doc.load();
  ui.edit('New local edits');
  await ui.respond({ markdown: 'Changed on disk', revision: 'r2', readOnly: false });
  await load;
  assert.equal(ui.doc.markdown, 'New local edits');
  assert.equal(ui.doc.revision, 'r1');
  assert.equal(ui.view.documentationDirty, true);
  assert.match(ui.status.textContent, /edits were kept/);
  assert.equal(ui.editors[0].editor.destroyed, 0);
});

test('save snapshots submitted text, conflicts retain edits, and Reset uses the last successful save', async (t) => {
  const ui = await setup(); t.after(() => ui.doc.destroy());
  ui.edit('Submitted');
  const save = ui.doc.save();
  assert.equal(ui.elements.get('[data-doc-save]').disabled, true);
  ui.edit('Edited during save');
  assert.equal(ui.bodies.at(-1).markdown, 'Submitted');
  await ui.respond({ revision: 'r2' }); await save;
  assert.equal(ui.doc.baseline, 'Submitted');
  assert.equal(ui.doc.dirty, true);
  const conflict = ui.doc.save();
  await ui.respond({ message: 'Changed externally' }, false); await conflict;
  assert.equal(ui.doc.markdown, 'Edited during save');
  assert.equal(ui.doc.revision, 'r2');
  assert.equal(ui.doc.dirty, true);
  assert.equal(ui.status.textContent, 'Changed externally');
  assert.deepEqual(ui.notifications, ['success', 'error']);
  ui.doc.reset();
  assert.equal(ui.doc.markdown, 'Submitted');
  assert.equal(ui.doc.dirty, false);
});

test('Save posts form fields that TYPO3 parses, preserving the component identifier and Markdown', async (t) => {
  const ui = await setup(); t.after(() => ui.doc.destroy());
  const markdown = '# Über uns\n\nA & B + C = 100%\n\n[Link](https://example.test/?a=1&b=2)\nこんにちは';
  ui.edit(markdown);
  ui.elements.get('[data-doc-save]').dispatchEvent(new Event('click'));
  const request = ui.requests.at(-1);
  assert.equal(request.method, 'POST');
  assert.match(request.headers['Content-Type'], /^application\/x-www-form-urlencoded\b/);
  assert.equal(request.credentials, 'same-origin');
  assert.equal(request.url.searchParams.get('identifier'), 'site:card');
  const form = new URLSearchParams(request.body);
  assert.equal(form.get('identifier'), 'site:card');
  assert.equal(form.get('markdown'), markdown);
  assert.equal(form.get('revision'), 'r1');
  await ui.respond({ revision: 'r2' });
  assert.equal(ui.doc.dirty, false);
});

test('Ctrl/Cmd+S saves documentation and stops the Controls shortcut from also handling it', async (t) => {
  const ui = await setup(); t.after(() => ui.doc.destroy());
  ui.edit('Shortcut edit');
  const event = new Event('keydown', { cancelable: true });
  let stopped = false;
  Object.assign(event, { key: 's', metaKey: true, stopPropagation: () => { stopped = true; } });
  ui.root.dispatchEvent(event);
  assert.equal(event.defaultPrevented, true);
  assert.equal(stopped, true);
  assert.equal(ui.bodies.at(-1).markdown, 'Shortcut edit');
  await ui.respond({ revision: 'r2' });
});

test('removing a view during editor startup destroys the late editor and ignores its callbacks', async () => {
  const ui = await setup({ deferEditor: true });
  ui.doc.destroy();
  ui.editors[0].resolve(); await ui.tick();
  assert.equal(ui.editors[0].editor.destroyed, 1);
  ui.edit('Stale callback');
  assert.equal(ui.doc.markdown, 'Original\n');
  assert.equal(ui.view.documentationDirty, false);
});

test('editor startup errors retain original Markdown and a reload can retry', async (t) => {
  const ui = await setup({ deferEditor: true }); t.after(() => ui.doc.destroy());
  ui.editors[0].reject(new Error('Editor startup failed')); await ui.tick();
  assert.equal(ui.status.textContent, 'Editor startup failed');
  assert.equal(ui.doc.markdown, 'Original\n');
  assert.equal(ui.elements.get('[data-doc-rich]').innerHTML, '<p>Original</p>');
  assert.equal(ui.doc.pending, false);
  const reload = ui.doc.load();
  await ui.respond({ markdown: 'Original\n', revision: 'r1', readOnly: false });
  ui.editors[1].resolve(); await reload;
  ui.editors[0].change('Stale callback');
  assert.equal(ui.doc.markdown, 'Original\n');
  ui.edit('Retried edit');
  assert.equal(ui.view.documentationDirty, true);
});

for (const initialMarkdown of ['Original\n', '']) {
  test(`Reset restores the baseline and rendered content after editor initialization fails (empty baseline: ${initialMarkdown === ''})`, async (t) => {
    const ui = await setup({ deferEditor: true, initialMarkdown }); t.after(() => ui.doc.destroy());
    ui.edit('Unsaved Markdown');
    ui.doc.reset();
    assert.equal(ui.doc.markdown, 'Unsaved Markdown', 'Reset must remain blocked while initialization is pending');
    ui.editors[0].reject(new Error('Editor startup failed')); await ui.tick();
    assert.equal(ui.doc.editor, null);
    assert.equal(ui.doc.pending, false);
    assert.equal(ui.doc.dirty, true);
    assert.equal(ui.elements.get('[data-doc-rich]').innerHTML, '<p>Unsaved Markdown</p>');
    assert.equal(ui.elements.get('[data-doc-reset]').disabled, false);
    ui.elements.get('[data-doc-reset]').dispatchEvent(new Event('click'));
    assert.equal(ui.doc.markdown, initialMarkdown);
    assert.equal(ui.doc.baseline, initialMarkdown);
    assert.equal(ui.doc.revision, 'r1');
    assert.equal(ui.elements.get('[data-doc-rich]').innerHTML, `<p>${initialMarkdown.trim()}</p>`);
    assert.equal(ui.doc.dirty, false);
    assert.equal(ui.view.documentationDirty, false);
    assert.equal(ui.elements.get('[data-doc-save]').disabled, true);
    assert.equal(ui.elements.get('[data-doc-reset]').disabled, true);
    assert.equal(ui.elements.get('[data-doc-save-state]').hidden, true);
    assert.equal(ui.bodies.length, 1, 'Reset must not request documentation again');
    assert.equal(ui.editors.length, 1, 'Reset must not retry editor initialization');
  });
}

test('Reset without an editor remains blocked during a save and restores the baseline after a failed save', async (t) => {
  const ui = await setup({ deferEditor: true }); t.after(() => ui.doc.destroy());
  ui.edit('Unsaved Markdown');
  ui.editors[0].reject(new Error('Editor startup failed')); await ui.tick();
  const save = ui.doc.save();
  ui.doc.reset();
  assert.equal(ui.doc.markdown, 'Unsaved Markdown');
  assert.equal(ui.doc.dirty, true);
  assert.equal(ui.elements.get('[data-doc-reset]').disabled, true);
  await ui.respond({ message: 'Save failed' }, false); await save;
  ui.doc.reset();
  assert.equal(ui.doc.markdown, 'Original\n');
  assert.equal(ui.doc.dirty, false);
  assert.equal(ui.view.documentationDirty, false);
  assert.equal(ui.elements.get('[data-doc-rich]').innerHTML, '<p>Original</p>');
  assert.equal(ui.bodies.length, 2);
});

test('Production renders Markdown inline without an editor or action buttons and cannot save', async (t) => {
  const ui = await setup({ readOnly: true }); t.after(() => ui.doc.destroy());
  assert.equal(ui.elements.get('[data-doc-status]').textContent, '');
  assert.equal(ui.editors.length, 0);
  assert.equal(ui.elements.get('[data-doc-rich]').innerHTML, '<p>Original</p>');
  assert.equal(ui.doc.saveButton, null);
  assert.equal(ui.doc.resetButton, null);
  await ui.doc.save();
  ui.doc.reset();
  assert.equal(ui.doc.markdown, 'Original\n');
  assert.equal(ui.doc.dirty, false);
  assert.equal(ui.bodies.length, 1);
});

test('disk changes refresh a clean editor in place and update the revision without dirtying it', async (t) => {
  const ui = await setup(); t.after(() => ui.doc.destroy());
  const editor = ui.doc.editor;
  ui.view.dispatchEvent(new Event('documentation'));
  assert.equal(ui.requests.at(-1).method, 'GET');
  await ui.respond({ markdown: 'Changed on disk\n', revision: 'r2', readOnly: false });
  assert.equal(ui.doc.editor, editor);
  assert.equal(ui.editors.length, 1);
  assert.equal(editor.destroyed, 0);
  assert.equal(editor.data, 'Changed on disk\n');
  assert.equal(ui.doc.markdown, 'Changed on disk\n');
  assert.equal(ui.doc.baseline, 'Changed on disk\n');
  assert.equal(ui.doc.revision, 'r2');
  assert.equal(ui.doc.dirty, false);
  assert.equal(ui.view.documentationDirty, false);
  assert.equal(ui.elements.get('[data-doc-save]').disabled, true);
});

test('disk changes preserve unsaved documentation, then refresh after Reset makes the editor clean', async (t) => {
  const ui = await setup(); t.after(() => ui.doc.destroy());
  ui.edit('Unsaved local content');
  ui.view.dispatchEvent(new Event('documentation'));
  assert.equal(ui.bodies.length, 1);
  assert.equal(ui.doc.markdown, 'Unsaved local content');
  assert.equal(ui.doc.revision, 'r1');
  ui.doc.reset();
  assert.equal(ui.requests.at(-1).method, 'GET');
  await ui.respond({ markdown: 'External edit', revision: 'r2', readOnly: false });
  assert.equal(ui.doc.markdown, 'External edit');
  assert.equal(ui.doc.dirty, false);
});

test('a second disk change during loading queues a refresh for the latest documentation', async (t) => {
  const ui = await setup(); t.after(() => ui.doc.destroy());
  ui.view.dispatchEvent(new Event('documentation'));
  ui.view.dispatchEvent(new Event('documentation'));
  assert.equal(ui.bodies.length, 2);
  await ui.respond({ markdown: 'Earlier disk content', revision: 'r2', readOnly: false });
  assert.equal(ui.bodies.length, 3);
  await ui.respond({ markdown: 'Latest disk content', revision: 'r3', readOnly: false });
  assert.equal(ui.doc.markdown, 'Latest disk content');
  assert.equal(ui.doc.revision, 'r3');
  assert.equal(ui.doc.dirty, false);
});

test('typing during an automatic disk refresh preserves local text and its original revision', async (t) => {
  const ui = await setup(); t.after(() => ui.doc.destroy());
  ui.view.dispatchEvent(new Event('documentation'));
  ui.edit('Typed while refreshing');
  await ui.respond({ markdown: 'External content', revision: 'r2', readOnly: false });
  assert.equal(ui.doc.markdown, 'Typed while refreshing');
  assert.equal(ui.doc.revision, 'r1');
  assert.equal(ui.doc.dirty, true);
});

test('disk changes during Save refresh once saving completes if no later edits remain', async (t) => {
  const ui = await setup(); t.after(() => ui.doc.destroy());
  ui.edit('Saved text');
  const save = ui.doc.save();
  ui.view.dispatchEvent(new Event('documentation'));
  assert.equal(ui.bodies.length, 2);
  await ui.respond({ revision: 'r2' }); await save;
  assert.equal(ui.requests.at(-1).method, 'GET');
  await ui.respond({ markdown: 'Latest disk content', revision: 'r3', readOnly: false });
  assert.equal(ui.doc.markdown, 'Latest disk content');
  assert.equal(ui.doc.dirty, false);
});

test('read-only disk refresh replaces rendered HTML without creating an editor', async (t) => {
  const ui = await setup({ readOnly: true }); t.after(() => ui.doc.destroy());
  assert.equal(ui.elements.get('[data-doc-status]').textContent, '');
  ui.view.dispatchEvent(new Event('documentation'));
  await ui.respond({ markdown: 'Updated documentation', revision: 'r2', readOnly: true });
  assert.equal(ui.elements.get('[data-doc-rich]').innerHTML, '<p>Updated documentation</p>');
  assert.equal(ui.editors.length, 0);
});

test('an SSE notification for our own saved revision preserves the current editor data and undo state', async (t) => {
  const ui = await setup(); t.after(() => ui.doc.destroy());
  let replacements = 0;
  ui.doc.editor.setData = () => { replacements++; };
  ui.view.dispatchEvent(new Event('documentation'));
  await ui.respond({ markdown: 'Original\n', revision: 'r1', readOnly: false });
  assert.equal(replacements, 0);
  assert.equal(ui.doc.dirty, false);
  assert.equal(ui.doc.pending, false);
  assert.doesNotMatch(ui.status.textContent, /Loading/);
});

test('saving empty documentation submits deletion and retains the missing revision as its new baseline', async (t) => {
  const ui = await setup(); t.after(() => ui.doc.destroy());
  ui.edit('');
  const save = ui.doc.save();
  assert.equal(ui.bodies.at(-1).markdown, '');
  await ui.respond({ markdown: '', revision: 'missing' }); await save;
  assert.equal(ui.doc.baseline, '');
  assert.equal(ui.doc.revision, 'missing');
  assert.equal(ui.doc.dirty, false);
  assert.equal(ui.elements.get('[data-doc-save]').disabled, true);
  assert.deepEqual(ui.notifications, ['success']);
  ui.edit('Recreate');
  const recreate = ui.doc.save();
  assert.equal(ui.bodies.at(-1).revision, 'missing');
  await ui.respond({ revision: 'recreated' }); await recreate;
  assert.equal(ui.doc.baseline, 'Recreate');
});
