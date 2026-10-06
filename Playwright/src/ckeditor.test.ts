import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { createContext, SourceTextModule, SyntheticModule } from 'node:vm';
import test from 'node:test';

const { DOMParser } = createRequire(import.meta.url)('linkedom');

async function setup(normalizedHtml?: string) {
  const document = new DOMParser().parseFromString('<html><body></body></html>', 'text/html');
  const context = createContext({ document, window: { document, DOMParser } });
  let config: any;
  let html = '';
  let destroyed = 0;
  let changed!: () => void;
  const editable = document.createElement('div');
  const fakeEditor = {
    getData: () => html,
    destroy: async () => { destroyed++; },
    ui: { getEditableElement: () => editable },
    model: { document: { on: (event: string, callback: () => void) => { assert.equal(event, 'change:data'); changed = callback; } } },
  };
  const converters = new SourceTextModule(await readFile(new URL('../../Resources/Public/JavaScript/Backend/markdown-editor.bundle.js', import.meta.url), 'utf8'), { context });
  await converters.link(() => { throw new Error('The Markdown bundle must be self-contained'); });
  const editor = new SourceTextModule(await readFile(new URL('../../Resources/Public/JavaScript/Backend/markdown-editor.js', import.meta.url), 'utf8'), { context });
  const names: Record<string, string[]> = {
    'editor-classic': ['ClassicEditor'], essentials: ['Essentials'], paragraph: ['Paragraph'], heading: ['Heading'],
    'basic-styles': ['Bold', 'Italic'], list: ['List'], link: ['Link'], 'code-block': ['CodeBlock'],
  };
  await editor.link(async (specifier) => {
    if (specifier.endsWith('markdown-editor.bundle.js')) return converters;
    const exports = names[specifier.replace('@ckeditor/ckeditor5-', '')];
    assert.ok(exports, `Unexpected CKEditor dependency ${specifier}`);
    const dependency = new SyntheticModule(exports, function () {
      for (const name of exports) this.setExport(name, name === 'ClassicEditor' ? {
        create: async (element: any, options: any) => {
          assert.ok(element.parentNode, 'CKEditor must be contained by the rich-mode wrapper');
          config = options;
          html = normalizedHtml ?? options.initialData;
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
    root: document.createElement('div'), editable,
    config: () => config, destroyed: () => destroyed,
    change: (data: string) => { html = data; changed(); },
  };
}

test('TYPO3 CKEditor modules initialize Markdown, expose formatting and serialize rich edits', async () => {
  const ui = await setup();
  const edits: string[] = [];
  const editor = await ui.create(ui.root, '# Heading\n\nParagraph', (markdown: string) => edits.push(markdown));
  assert.equal(ui.config().licenseKey, 'GPL');
  assert.equal(ui.config().plugins.length, 8);
  assert.ok(ui.config().toolbar.includes('codeBlock'));
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
