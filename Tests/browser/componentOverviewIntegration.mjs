import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test, { before, after } from 'node:test';

import { chromium } from '@playwright/test';
const resources = new URL('../../Resources/Public/', import.meta.url);
const css = (await Promise.all(['variant-view.css', 'component-markdown.css', 'component-documentation.css', 'component-overview.css'].map(name => readFile(new URL(`Css/Backend/${name}`, resources), 'utf8')))).join('\n');
const imports = {
  '@andersundsehr/frontend-studio/backend/': '/Backend/',
  '@andersundsehr/frontend-studio/vendor/inline-documentation-editor': '/Contrib/inline-documentation-editor.js',
  '@andersundsehr/frontend-studio/vendor/markdown-converter': '/Contrib/markdown-converter.js',
  '@andersundsehr/frontend-studio/vendor/code-highlighting': '/Contrib/code-highlighting.js',
  '@typo3/core/document-service.js': '/document-service.js',
  '@typo3/backend/notification.js': '/notification.js',
  '@typo3/backend/module.js': '/module.js',
  '@typo3/backend/viewport.js': '/viewport.js',
  '@typo3/backend/storage/module-state-storage.js': '/module-state.js',
  '@typo3/backend/tree/tree.js': '/tree.js',
  '@typo3/backend/tree/tree-toolbar.js': '/tree-toolbar.js',
  '@typo3/backend/': '/stub/',
  '@typo3/core/ajax/ajax-request.js': '/stub/ajax.js',
  lit: '/lit.js',
};
const head = `<!doctype html><style>body{margin:0;font-family:system-ui}:root{--module-docheader-bar-height:0px;--module-docheader-border-width:0px}${css}</style><script type="importmap">${JSON.stringify({ imports })}</script>`;
let browser;
before(async () => { browser = await chromium.launch({ ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) }); });
after(async () => { await browser?.close(); });

async function setup(t, version = 14) {
  const page = await browser.newPage({ viewport: { width: 1100, height: 850 } });
  page.setDefaultTimeout(10000);
  const errors = [];
  const dialogs = [];
  const previews = [];
  const documents = [];
  const server = { markdown: '# Documentation\n\nOriginal paragraph\n', revision: 'r1', fixture: 'Original fixture' };
  page.on('pageerror', error => errors.push(error.message));
  page.on('dialog', async dialog => { dialogs.push(dialog.type()); await dialog.dismiss(); });
  t.after(async () => { await page.close(); assert.deepEqual(errors, []); });
  await page.route('https://studio.test/**', async route => {
    const url = new URL(route.request().url());
    const path = url.pathname;
    if (path === '/docs') {
      documents.push(route.request().method());
      return route.fulfill({ json: { ...server, readOnly: false } });
    }
    if (path === '/preview') {
      previews.push(url.searchParams.get('componentVariant'));
      return route.fulfill({ contentType: 'text/html', body: '<!doctype html><body><p>' + server.fixture + '</p></body>' });
    }
    const file = path.startsWith('/Backend/') ? `JavaScript${path}` : path.startsWith('/Contrib/') ? path.slice(1) : null;
    if (file) return route.fulfill({ contentType: 'text/javascript', body: await readFile(new URL(file, resources), 'utf8') });
    const modules = {
      '/document-service.js': 'export default { ready: () => Promise.resolve() };',
      '/notification.js': 'export default { success() {}, error() {} };',
      '/lit.js': 'export class LitElement extends HTMLElement { connectedCallback() {} disconnectedCallback() {} } export const html = () => "";',
      '/tree.js': 'export class Tree extends HTMLElement {}',
      '/tree-toolbar.js': 'export class TreeToolbar extends HTMLElement {}',
      '/module.js': 'export const ModuleUtility = { getFromName: () => ({ link: "/module?token=module" }) };',
      '/viewport.js': 'export default window.viewport;',
      '/module-state.js': `export const ModuleStateStorage = {
        current: () => window.stored,
        updateWithTreeIdentifier: (type, identifier, treeIdentifier) => {
          window.order.push('persist'); window.persisted.push({ type, identifier, treeIdentifier });
          window.stored = { identifier, treeIdentifier };
        }
      };`,
    };
    if (path in modules) return route.fulfill({ contentType: 'text/javascript', body: modules[path] });
    if (path.startsWith('/stub/')) return route.fulfill({ contentType: 'text/javascript', body: 'export default {}; export const TreeNodePositionEnum = {}; export const SeverityEnum = {};' });
    if (path === '/backend') {
      return route.fulfill({ contentType: 'text/html', body: `${head}<script type="module">
        window.order = []; window.persisted = []; window.navigations = []; window.streams = []; window.contentLoads = 0; window.treeLoads = 0;
        window.stored = { identifier: 'site:card', treeIdentifier: 'site_site:card' };
        window.TYPO3 = { settings: { ajaxUrls: {} }, ModuleMenu: { App: { getCurrentModule: () => 'admin_frontendstudio' } }, Backend: { NavigationContainer: { showComponent() {} } } };
        window.EventSource = class extends EventTarget {
          constructor(uri) { super(); this.uri = uri; this.closed = false; window.streams.push(this); }
          close() { this.closed = true; }
        };
        window.viewport = { ContentContainer: {
          get: () => document.querySelector('#content').contentWindow,
          getUrl: () => document.querySelector('#content').src,
          setUrl: url => {
            window.order.push('navigate'); window.navigations.push(String(url));
            document.querySelector('#content').src = String(url);
          }
        } };
        document.addEventListener('frontend-studio:before-navigate', () => window.order.push('confirm'));
        await import('/Backend/component-tree-container.js');
        const container = document.createElement('andersundsehr-frontend-studio-component-tree-container');
        window.container = container;
        const nodes = [
          { identifier: 'site:card', nodeType: 'component', __treeIdentifier: 'site_site:card', checked: true },
          ...['Default', 'Mobile:Dark'].map(name => ({ identifier: 'site:card:' + name, nodeType: 'variant', __treeIdentifier: 'site_site:card_site:card:' + name, checked: false }))
        ];
        container.tree = {
          nodes, getSelectedNodes: () => nodes.filter(node => node.checked),
          resetSelectedNodes: () => nodes.forEach(node => { node.checked = false; }),
          expandNodeParents: async () => {}, focusNode() {},
          selectNode: (node, propagate = true) => {
            container.tree.resetSelectedNodes(); node.checked = true;
            container.dispatchEvent(new CustomEvent('typo3:tree:node-selected', { detail: { node, propagate } }));
          },
          loadData: async () => { window.treeLoads++; container.tree.nodes = nodes; }, refreshOrFilterTree: async () => {},
          ${version >= 14 ? 'scrollNodeIntoViewIfNeeded() {},' : ''}
        };
        container.treeInitialized = true;
        container.addEventListener('typo3:tree:node-selected', container.loadVariant);
        document.body.append(container);
        const frame = document.createElement('iframe'); frame.id = 'content'; frame.style = 'width:100%;height:800px;border:0';
        frame.src = '/module?token=module&component=site%3Acard&site=preview&language=de-DE';
        frame.addEventListener('load', () => window.contentLoads++);
        document.body.append(frame);
      </script>` });
    }
    const overview = url.searchParams.has('component');
    const identifier = url.searchParams.get('componentVariant') || 'site:card:Default';
    const preview = '/preview?componentVariant=' + encodeURIComponent(identifier) + '&site=preview&language=de-DE';
    const doc = `<section class="frontend-studio-documentation" data-component-documentation data-component-identifier="site:card" data-doc-uri="/docs">
      <div class="frontend-studio-variant-values-actions" data-doc-actions><button data-doc-reset>Reset</button><button data-doc-save>Save</button><span data-doc-save-state hidden></span><div data-doc-editor-controls></div></div>
      <div data-overview-description><div class="frontend-studio-markdown" data-overview-description-content data-doc-rich><pre data-overview-markdown></pre></div></div>
      <button data-overview-description-toggle hidden></button><p data-doc-status></p>
    </section>`;
    const previewFrame = (name, first) => `<section><div data-overview-frame-container><iframe src="/preview?componentVariant=${encodeURIComponent('site:card:' + name)}&site=preview&language=de-DE" ${overview ? 'data-overview-frame' : ''} data-variant-identifier="site:card:${name}" ${first ? 'data-frontend-studio-variant-frame' : ''}></iframe></div><p data-overview-frame-status></p></section>`;
    const links = overview ? ['Default', 'Mobile:Dark'].map(name => `<h2><a data-overview-variant-link href="/module?token=module&componentVariant=${encodeURIComponent('site:card:' + name)}&site=preview&language=de-DE"><span>${name}</span></a></h2>`).join('') : '';
    return route.fulfill({ contentType: 'text/html', body: `${head}<main class="frontend-studio-overview" ${overview ? 'data-component-overview' : ''} data-frontend-studio-variant-view
      data-component-identifier="site:card" data-variant-identifier="${identifier}" data-preview-uri="${preview}" data-component-change-stream-uri="/changes?token=stream">
      ${doc}${links}${previewFrame(overview ? 'Default' : identifier.split(':').slice(2).join(':'), true)}${overview ? previewFrame('Mobile:Dark', false) : ''}
      </main><script type="module">
        import { getVariantState } from '/Backend/variant-state.js';
        const root = document.querySelector('main'); window.view = getVariantState(root);
        if (${overview}) {
          await import('/Backend/component-overview.js');
          await window.view.features.get(root)?.documentationReady;
        } else {
          const { default: Inspector } = await import('/Backend/variant-view.js'); await Inspector.initialize();
          const { default: Documentation } = await import('/Backend/component-documentation.js');
          window.view.mount('documentation', () => new Documentation(root.querySelector('[data-component-documentation]'), window.view));
        }
        await import('/Backend/component-tree-startup.js');
        window.ready = true;
      </script>` });
  });
  await page.goto('https://studio.test/backend');
  const frame = () => page.frames().find(frame => frame.url().includes('/module?'));
  const ready = async (overview = true) => {
    await page.waitForFunction(overview => {
      const content = document.querySelector('#content')?.contentWindow;
      return content?.location.href.includes(overview ? 'component=' : 'componentVariant=') && content.ready && window.streams.length === 1;
    }, overview);
    await page.frameLocator('#content').getByRole('textbox', { name: 'Component documentation rich text' }).waitFor();
  };
  await ready();
  const emit = payload => page.evaluate(payload => window.streams[0].dispatchEvent(new MessageEvent('component-files-changed', { data: JSON.stringify(payload) })), payload);
  return { page, frame, ready, emit, server, previews, documents, dialogs };
}

for (const version of [13, 14]) {
  test(`Overview variant links use the tree guard and preserve context on TYPO3 ${version}`, async t => {
    const ui = await setup(t, version);
    const { page, dialogs } = ui;
    await ui.frame().locator('.tiptap').click();
    await ui.frame().getByRole('button', { name: 'Edit Markdown source' }).click();
    await ui.frame().locator('textarea').fill('# Unsaved documentation\n');
    const link = ui.frame().getByRole('link', { name: 'Mobile:Dark' });
    await link.click();
    assert.deepEqual(dialogs, ['confirm']);
    assert.equal(await ui.frame().locator('textarea').inputValue(), '# Unsaved documentation\n');
    assert.deepEqual(await page.evaluate(() => ({ order, stored, selected: container.getSelectedNode().identifier, navigations })), {
      order: ['confirm'], stored: { identifier: 'site:card', treeIdentifier: 'site_site:card' }, selected: 'site:card', navigations: [],
    });
    page.removeAllListeners('dialog');
    page.on('dialog', async dialog => { dialogs.push(dialog.type()); await dialog.accept(); });
    // Filtered navigation uses the real TYPO3 Tree in componentTreeFiltering.mjs.
    await link.locator('span').click();
    await ui.ready(false);
    assert.deepEqual(dialogs, ['confirm', 'confirm'], 'discard confirmation must not be followed by a native unload dialog');
    const state = await page.evaluate(() => ({ order, stored, selected: container.getSelectedNode().identifier, navigations, persisted, streams: streams.map(stream => ({ uri: stream.uri, closed: stream.closed })) }));
    assert.deepEqual(state.order, ['confirm', 'confirm', 'persist', 'navigate']);
    assert.equal(state.selected, 'site:card:Mobile:Dark');
    assert.deepEqual(state.stored, { identifier: state.selected, treeIdentifier: 'site_site:card_site:card:Mobile:Dark' });
    assert.equal(state.persisted.length, 1);
    assert.equal(state.navigations.length, 1);
    assert.equal(await page.evaluate(() => treeLoads), 0);
    const url = new URL(state.navigations[0]);
    assert.equal(url.searchParams.get('componentVariant'), state.selected);
    assert.equal(url.searchParams.has('component'), false);
    assert.equal(url.searchParams.get('site'), 'preview');
    assert.equal(url.searchParams.get('language'), 'de-DE');
    assert.equal(url.searchParams.get('token'), 'module');
    assert.deepEqual(state.streams, [{ uri: '/changes?token=stream', closed: false }]);
  });
}

for (const type of ['Overview', 'Variant Inspector']) {
  test(`${type} receives fixture and documentation SSE updates without losing unsaved docs`, async t => {
    const ui = await setup(t);
    const { page, server, documents } = ui;
    if (type === 'Variant Inspector') {
      await ui.frame().getByRole('link', { name: 'Default' }).click();
      await ui.ready(false);
    }
    const variants = type === 'Overview' ? ['Default', 'Mobile:Dark'] : ['Default'];
    for (const name of variants) await ui.frame().frameLocator(`iframe[data-variant-identifier="site:card:${name}"]`).getByText('Original fixture').waitFor();
    server.markdown = '# External documentation\n'; server.revision = 'r2';
    await ui.emit({ componentIdentifiers: [], documentationComponentIdentifiers: ['site:card'] });
    await ui.frame().getByRole('heading', { name: 'External documentation' }).waitFor();
    await ui.frame().locator('.tiptap').click();
    await ui.frame().getByRole('button', { name: 'Edit Markdown source' }).click();
    await ui.frame().locator('textarea').fill('# My unsaved documentation\n');
    const loads = await page.evaluate(() => contentLoads);
    const docRequests = documents.length;
    const before = [...ui.previews];
    server.markdown = '# A later disk change\n'; server.revision = 'r3';
    server.fixture = 'Changed fixture';
    const previewReloads = variants.map(name => page.waitForResponse(response => {
      const url = new URL(response.url());
      return url.pathname === '/preview' && url.searchParams.get('componentVariant') === 'site:card:' + name;
    }));
    await ui.emit({ componentIdentifiers: ['site:card'], documentationComponentIdentifiers: ['site:card'] });
    await Promise.all(previewReloads);
    await ui.frame().waitForFunction(() => window.view.previewChanged);
    for (const name of variants) {
      const identifier = 'site:card:' + name;
      await ui.frame().frameLocator(`iframe[data-variant-identifier="${identifier}"]`).getByText('Changed fixture').waitFor();
      assert.ok(ui.previews.filter(value => value === identifier).length > before.filter(value => value === identifier).length, name + ' preview must refresh');
    }
    assert.equal(await page.evaluate(() => contentLoads), loads);
    assert.equal(await ui.frame().locator('textarea').inputValue(), '# My unsaved documentation\n');
    assert.equal(documents.length, docRequests, 'dirty documentation must not be fetched over local edits');
    await ui.frame().getByRole('button', { name: 'Reset', exact: true }).click();
    await ui.frame().waitForFunction(() => document.querySelector('textarea').value === '# A later disk change\n');
    await ui.frame().getByRole('heading', { name: 'A later disk change' }).waitFor();
    await ui.emit({ componentIdentifiers: ['site:card'] });
    await page.waitForFunction(before => contentLoads > before, loads);
    await ui.ready(type === 'Overview');
    assert.deepEqual(await page.evaluate(() => streams.map(stream => ({ uri: stream.uri, closed: stream.closed }))), [{ uri: '/changes?token=stream', closed: false }]);
  });
}
