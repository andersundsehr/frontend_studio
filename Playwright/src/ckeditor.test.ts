import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { createContext, SourceTextModule, SyntheticModule } from 'node:vm';
import test from 'node:test';

const { DOMParser } = createRequire(import.meta.url)('linkedom');

async function setup(normalizedHtml?: string, normalize = (html: string) => html) {
  const document = new DOMParser().parseFromString('<html><body></body></html>', 'text/html');
  const context = createContext({ document, window: { document, DOMParser } });
  let config: any;
  let html = '';
  let destroyed = 0;
  let changed!: () => void;
  const editable = document.createElement('div');
  const handlers: Array<{ event: string; callback: (...args: any[]) => void; priority: string }> = [];
  let sourceMode = false;
  const sourceEditing = {
    on: (event: string, callback: (...args: any[]) => void, options: { priority: string }) => handlers.push({ event, callback, priority: options.priority }),
    get isSourceEditingMode() { return sourceMode; },
    set isSourceEditingMode(value: boolean) {
      const event = { return: undefined as boolean | undefined, stop() {} };
      handlers.filter(h => h.event === 'set:isSourceEditingMode').forEach(h => h.callback(event, 'isSourceEditingMode', value));
      value = event.return ?? value;
      if (value === sourceMode) return;
      sourceMode = value;
      handlers.filter(h => h.event === 'change:isSourceEditingMode' && h.priority === 'highest').forEach(h => h.callback());
      if (value) {
        const wrapper = document.createElement('div'); wrapper.className = 'ck-source-editing-area';
        const textarea = document.createElement('textarea'); textarea.value = fakeEditor.getData();
        wrapper.append(textarea); root.append(wrapper);
      } else {
        const textarea = root.querySelector('textarea');
        html = normalize(fakeEditor.data.processor.toView(textarea.value));
        changed?.();
        textarea.parentElement.remove();
      }
      handlers.filter(h => h.event === 'change:isSourceEditingMode' && h.priority === 'lowest').forEach(h => h.callback());
    },
  };
  const root = document.createElement('div');
  const fakeEditor = {
    data: {
      processor: { toView: (data: string) => data, toData: (view: string) => view },
      parse: (data: string) => normalize(fakeEditor.data.processor.toView(data)),
      stringify: (view: string) => fakeEditor.data.processor.toData(view),
    },
    plugins: { get: (plugin: any) => { assert.equal(plugin.name, 'MarkdownSourceEditing'); return sourceEditing; } },
    setData: (data: string) => { html = normalize(fakeEditor.data.processor.toView(data)); changed?.(); },
    getData: () => fakeEditor.data.processor.toData(html),
    destroy: async () => { destroyed++; },
    ui: { getEditableElement: () => editable },
    model: { document: { on: (event: string, callback: () => void) => { assert.equal(event, 'change:data'); changed = callback; } } },
  };
  const converters = new SourceTextModule(await readFile(new URL('../../Resources/Public/Contrib/markdown-converter.js', import.meta.url), 'utf8'), { context });
  await converters.link(() => { throw new Error('The Markdown bundle must be self-contained'); });
  const editor = new SourceTextModule(await readFile(new URL('../../Resources/Public/JavaScript/Backend/markdown-editor.js', import.meta.url), 'utf8'), { context });
  const names: Record<string, string[]> = {
    'editor-classic': ['ClassicEditor'], essentials: ['Essentials'], paragraph: ['Paragraph'], heading: ['Heading'],
    core: ['Plugin'], 'source-editing': ['SourceEditing'], 'basic-styles': ['Bold', 'Italic'], list: ['List'], link: ['Link'], 'code-block': ['CodeBlock'],
  };
  await editor.link(async (specifier) => {
    if (specifier === '@andersundsehr/frontend-studio/vendor/markdown-converter') return converters;
    const exports = names[specifier.replace('@ckeditor/ckeditor5-', '')];
    assert.ok(exports, `Unexpected CKEditor dependency ${specifier}`);
    const dependency = new SyntheticModule(exports, function () {
      for (const name of exports) this.setExport(name, name === 'ClassicEditor' ? {
        create: async (element: any, options: any) => {
          assert.ok(element.parentNode, 'CKEditor must be contained by the rich-mode wrapper');
          config = options;
          const markdownPlugin = new options.plugins[0]();
          markdownPlugin.editor = fakeEditor; markdownPlugin.init();
          html = normalizedHtml ?? normalize(fakeEditor.data.processor.toView(options.initialData));
          return fakeEditor;
        },
      } : class {});
    }, { context });
    await dependency.link(() => { throw new Error('Unexpected mock import'); });
    return dependency;
  });
  await editor.evaluate();
  return {
    create: (editor.namespace as any).createEditor,
    root, editable, sourceEditing,
    config: () => config, destroyed: () => destroyed,
    change: (data: string) => { html = data; changed(); },
  };
}

test('TYPO3 CKEditor modules initialize Markdown, expose formatting and serialize rich edits', async () => {
  const ui = await setup();
  const edits: string[] = [];
  const editor = await ui.create(ui.root, '# Heading\n\nParagraph', (markdown: string) => edits.push(markdown));
  assert.equal(ui.config().licenseKey, 'GPL');
  assert.equal(ui.config().plugins.length, 10);
  assert.ok(ui.config().toolbar.includes('markdownSource'));
  assert.ok(ui.config().toolbar.includes('undo'));
  assert.deepEqual(Array.from(ui.config().link.allowedProtocols), ['http', 'https', 'mailto', 'tel']);
  assert.equal(ui.editable.getAttribute('aria-label'), 'Component documentation rich text');
  assert.equal(edits.length, 0, 'initialization must not mark documentation dirty');
  ui.change('<p><strong>Bold</strong> and <em>italic</em></p><ul><li>One</li><li>Two</li></ul>');
  assert.equal(edits[0], '**Bold** and *italic*\n\n* One\n* Two');
  ui.change('<p><a href="javascript:alert(1)">Unsafe</a></p>');
  assert.equal(edits[1], 'Unsafe');
  await editor.destroy();
  assert.equal(ui.destroyed(), 1);
});

test('existing noncanonical Markdown opens in CKEditor without an initial edit or source switch', async () => {
  const ui = await setup('<p>Original</p>');
  const changes: string[] = [];
  const editor = await ui.create(ui.root, 'Original\n', (markdown: string) => changes.push(markdown));
  assert.equal(ui.destroyed(), 0);
  assert.equal(changes.length, 0);
  ui.change('<p>Edited</p>');
  assert.deepEqual(changes, ['Edited']);
  await editor.destroy();
});

test('Markdown source edits are dirty immediately and roundtrip verbatim through source toggles', async () => {
  const ui = await setup();
  const changes: string[] = [];
  const editor = await ui.create(ui.root, 'Original\n', (markdown: string) => changes.push(markdown));
  ui.sourceEditing.isSourceEditingMode = true;
  const textarea = ui.root.querySelector('textarea');
  assert.equal(textarea.value, 'Original\n');
  assert.equal(textarea.getAttribute('aria-label'), 'Component documentation Markdown');
  ui.sourceEditing.isSourceEditingMode = false;
  assert.deepEqual(changes, [], 'opening and closing source must not create an edit');
  ui.sourceEditing.isSourceEditingMode = true;
  const source = ui.root.querySelector('textarea');
  source.value = '# Raw Markdown\n\n*custom*\n';
  source.dispatchEvent(new ui.root.ownerDocument.defaultView.Event('input'));
  assert.deepEqual(changes, ['# Raw Markdown\n\n*custom*\n']);
  ui.sourceEditing.isSourceEditingMode = false;
  assert.equal(changes.length, 1, 'source conversion must not normalize the pending save');
  ui.sourceEditing.isSourceEditingMode = true;
  assert.equal(ui.root.querySelector('textarea').value, changes[0]);
  editor.setData('Reset from disk\n');
  assert.equal(ui.sourceEditing.isSourceEditingMode, false);
  ui.sourceEditing.isSourceEditingMode = true;
  assert.equal(ui.root.querySelector('textarea').value, 'Reset from disk\n');
  await editor.destroy();
});

// Simulate the content CKEditor's configured schema drops during import.
const supportedHtml = (html: string) => html
  .replace(/<img\b[^>]*>|<hr\b[^>]*>/g, '')
  .replace(/<\/?(?:blockquote|code|table|thead|tbody|tr|th|td)\b[^>]*>/g, '');

test('unsupported Markdown opens verbatim in source mode and cannot switch to lossy rich text', async (t) => {
  for (const unsupported of ['![Screenshot](foo.png)', '> Quote', 'Use `inline code`.', '---', '| A | B |\n| --- | --- |\n| 1 | 2 |']) {
    await t.test(unsupported, async () => {
      const ui = await setup(undefined, supportedHtml);
      const changes: string[] = [];
      const source = `${unsupported}\n\nOriginal paragraph\n`;
      const editor = await ui.create(ui.root, source, (markdown: string) => changes.push(markdown));
      assert.equal(ui.sourceEditing.isSourceEditingMode, true);
      assert.equal(ui.root.querySelector('textarea').value, source);
      assert.equal(ui.root.querySelector('[role="status"]').hidden, false);
      assert.deepEqual(changes, [], 'compatibility checks and opening source must not create edits');
      const edited = source.replace('Original paragraph', 'Edited paragraph');
      const textarea = ui.root.querySelector('textarea');
      textarea.value = edited;
      textarea.dispatchEvent(new ui.root.ownerDocument.defaultView.Event('input'));
      ui.sourceEditing.isSourceEditingMode = false;
      assert.equal(ui.sourceEditing.isSourceEditingMode, true);
      assert.equal(ui.root.querySelector('textarea').value, edited);
      assert.deepEqual(changes, [edited], 'editing other text must keep unsupported content intact');
      await editor.destroy();
    });
  }
});

test('source edits and reset recheck compatibility without rewriting Markdown or creating edits', async () => {
  const ui = await setup(undefined, supportedHtml);
  const changes: string[] = [];
  const editor = await ui.create(ui.root, 'Original\n', (markdown: string) => changes.push(markdown));
  ui.sourceEditing.isSourceEditingMode = true;
  const textarea = ui.root.querySelector('textarea');
  const source = '![New image](foo.png)\n\nOriginal\n';
  textarea.value = source;
  textarea.dispatchEvent(new ui.root.ownerDocument.defaultView.Event('input'));
  ui.sourceEditing.isSourceEditingMode = false;
  assert.equal(ui.sourceEditing.isSourceEditingMode, true);
  assert.deepEqual(changes, [source]);
  editor.setData('![External image](bar.png)\n');
  assert.equal(ui.sourceEditing.isSourceEditingMode, true);
  assert.equal(ui.root.querySelector('textarea').value, '![External image](bar.png)\n');
  assert.deepEqual(changes, [source], 'reset/reload must not emit changes from the previous source');
  editor.setData('Reset\n');
  assert.equal(ui.sourceEditing.isSourceEditingMode, false);
  assert.equal(ui.root.querySelector('[role="status"]').hidden, true);
  assert.deepEqual(changes, [source]);
  ui.sourceEditing.isSourceEditingMode = true;
  const supported = ui.root.querySelector('textarea');
  supported.value = '# Supported again\n';
  supported.dispatchEvent(new ui.root.ownerDocument.defaultView.Event('input'));
  ui.sourceEditing.isSourceEditingMode = false;
  assert.equal(ui.sourceEditing.isSourceEditingMode, false);
  assert.deepEqual(changes, [source, '# Supported again\n']);
  await editor.destroy();
});
