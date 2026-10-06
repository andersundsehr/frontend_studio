import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createContext, SourceTextModule, SyntheticModule } from 'node:vm';
import test from 'node:test';

async function setup({ deferEditor = false, readOnly = false } = {}) {
  const elements = new Map<string, any>();
  for (const name of ['rich', 'status', ...(!readOnly ? ['save', 'reset', 'save-state', 'actions'] : [])]) {
    elements.set(`[data-doc-${name}]`, Object.assign(new EventTarget(), { textContent: '', innerHTML: '', hidden: false, disabled: false }));
  }
  const root = Object.assign(new EventTarget(), { dataset: { docUri: '/docs', componentIdentifier: 'site:card' }, querySelector: (name: string) => elements.get(name) ?? null });
  const view = Object.assign(new EventTarget(), { documentationDirty: false });
  const pending: Array<(value: unknown) => void> = [];
  const bodies: any[] = [];
  const notifications: string[] = [];
  const window = Object.assign(new EventTarget(), { location: { href: 'https://example.test' }, confirm: () => true });
  const context = createContext({
    Event, EventTarget, AbortController, URL, window, top: { document: new EventTarget() },
    fetch: async (_url: URL, options: any) => {
      bodies.push(options.body ? JSON.parse(options.body) : null);
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
  await respond({ markdown: 'Original\n', revision: 'r1', readOnly });
  if (!deferEditor && !readOnly) { editors[0].resolve(); await tick(); }
  return { doc, root, view, bodies, respond, editors, elements, notifications, tick, status: elements.get('[data-doc-status]'),
    edit: (markdown: string) => editors.at(-1).change(markdown) };
}

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
  assert.equal(ui.doc.editor.data, '<p>Original</p>');
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
  ui.editors[0].reject(new Error('CKEditor startup failed')); await ui.tick();
  assert.equal(ui.status.textContent, 'CKEditor startup failed');
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

test('Production renders Markdown inline without an editor or action buttons and cannot save', async (t) => {
  const ui = await setup({ readOnly: true }); t.after(() => ui.doc.destroy());
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
