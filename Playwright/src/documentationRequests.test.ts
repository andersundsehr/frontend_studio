import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createContext, SourceTextModule, SyntheticModule } from 'node:vm';
import test from 'node:test';

async function setup() {
  const elements = new Map<string, any>();
  for (const name of ['source', 'rich', 'preview', 'status', 'save', 'mode', 'toolbar', 'reload']) {
    elements.set(`[data-doc-${name}]`, Object.assign(new EventTarget(), { value: '', textContent: '', hidden: false, disabled: false }));
  }
  const root = { dataset: { docUri: '/docs', componentIdentifier: 'site:card' }, querySelector: (name: string) => elements.get(name) };
  const view = Object.assign(new EventTarget(), { documentationDirty: false });
  const pending: Array<(value: unknown) => void> = [];
  const bodies: any[] = [];
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
  const markdown = new SyntheticModule(['canEditRichText', 'createEditor', 'renderMarkdown'], function () {
    this.setExport('canEditRichText', () => false);
    this.setExport('createEditor', () => { throw new Error('Source-mode test'); });
    this.setExport('renderMarkdown', (source: string) => source);
  }, { context });
  await markdown.link(() => { throw new Error('Unexpected import'); });
  const module = new SourceTextModule(await readFile(new URL('../../Resources/Public/JavaScript/Backend/component-documentation.js', import.meta.url), 'utf8'), { context });
  await module.link(specifier => specifier.endsWith('variant-lifecycle.js') ? lifecycle : markdown);
  await module.evaluate();
  const Documentation = (module.namespace as any).default;
  const doc = new Documentation(root, view);
  const respond = async (body: unknown, ok = true) => {
    pending.shift()!({ ok, json: async () => body });
    await new Promise(setImmediate);
  };
  await respond({ markdown: 'Original\n', revision: 'r1', readOnly: false });
  return { doc, view, bodies, respond, source: elements.get('[data-doc-source]'), status: elements.get('[data-doc-status]') };
}

test('a late reload preserves documentation typed while the request was pending', async (t) => {
  const ui = await setup(); t.after(() => ui.doc.destroy());
  const load = ui.doc.load();
  ui.source.value = 'New local edits'; ui.source.dispatchEvent(new Event('input'));
  await ui.respond({ markdown: 'Changed on disk', revision: 'r2', readOnly: false });
  await load;
  assert.equal(ui.source.value, 'New local edits');
  assert.equal(ui.doc.revision, 'r1');
  assert.equal(ui.view.documentationDirty, true);
  assert.match(ui.status.textContent, /edits were kept/);
});

test('save snapshots only submitted text and conflicts retain pending edits', async (t) => {
  const ui = await setup(); t.after(() => ui.doc.destroy());
  ui.source.value = 'Submitted'; ui.source.dispatchEvent(new Event('input'));
  const save = ui.doc.save();
  ui.source.value = 'Edited during save'; ui.source.dispatchEvent(new Event('input'));
  assert.equal(ui.bodies.at(-1).markdown, 'Submitted');
  await ui.respond({ revision: 'r2' }); await save;
  assert.equal(ui.doc.baseline, 'Submitted');
  assert.equal(ui.doc.dirty, true);
  const conflict = ui.doc.save();
  await ui.respond({ message: 'Changed externally' }, false); await conflict;
  assert.equal(ui.source.value, 'Edited during save');
  assert.equal(ui.doc.revision, 'r2');
  assert.equal(ui.doc.dirty, true);
  assert.equal(ui.status.textContent, 'Changed externally');
});
