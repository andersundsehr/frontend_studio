import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test, { before, after } from 'node:test';
import { chromium, expect } from '@playwright/test';
import { typo3ReleaseModule } from './helpers/typo3Release.mjs';

const resources = new URL('../../Resources/Public/', import.meta.url);
const component = 'site:card';
const target = 'site:card:Mobile:Dark';
const nodes = [
  { identifier: 'site', nodeType: 'folder', name: 'Site', depth: 0, hasChildren: true },
  { identifier: component, nodeType: 'component', name: 'Card', depth: 1, hasChildren: true },
  ...['Default', 'Mobile:Dark'].map(name => ({ identifier: `${component}:${name}`, nodeType: 'variant', name, depth: 2, hasChildren: false })),
];
const imports = {
  '@andersundsehr/frontend-studio/backend/': '/Backend/',
  '@andersundsehr/frontend-studio/vendor/inline-documentation-editor': '/Contrib/inline-documentation-editor.js',
  '@andersundsehr/frontend-studio/vendor/markdown-converter': '/Contrib/markdown-converter.js',
  '@andersundsehr/frontend-studio/vendor/code-highlighting': '/Contrib/code-highlighting.js',
  '@typo3/backend/': '/typo3/backend/',
  '@typo3/core/': '/typo3/core/',
  '~labels/': '/labels/',
  bootstrap: '/empty.js',
};
for (const name of ['lit', 'lit-html', 'lit-element', '@lit/reactive-element']) {
  const entry = name === 'lit-html' ? 'lit-html.js' : name === '@lit/reactive-element' ? 'reactive-element.js' : 'index.js';
  imports[name] = `/typo3/core/Contrib/${name}/${entry}`;
  imports[`${name}/`] = `/typo3/core/Contrib/${name}/`;
}
const head = `<!doctype html><script type="importmap">${JSON.stringify({ imports })}</script>`;
let browser;
before(async () => { browser = await chromium.launch({ ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) }); });
after(async () => { await browser?.close(); });

async function setup(t, version, query = 'component=site%3Acard&site=preview&language=de-DE', treeNodes = nodes) {
  const page = await browser.newPage({ viewport: { width: 1100, height: 850 } });
  page.setDefaultTimeout(15000);
  const errors = [];
  const requests = [];
  const snapshotRequests = [];
  const loadedModules = new Set();
  page.on('pageerror', error => errors.push(error.message));
  t.after(async () => { await page.close(); assert.deepEqual(errors, []); });
  // Only unrelated backend chrome is stubbed. Tree, TreeToolbar, Lit, debounce,
  // Ajax and both storage APIs come from the pinned TYPO3 release, unchanged.
  const stubs = {
    '/empty.js': '',
    '/typo3/backend/element/icon-element.js': '',
    '/typo3/backend/viewport/content-navigation-toggle.js': '',
    '/typo3/backend/modal.js': `import { render } from 'lit';
      export default { sizes: { large: 'large' }, advanced(options) {
        const modal = new EventTarget();
        const root = document.createElement('main');
        root.setAttribute('role', 'dialog');
        root.style.cssText = 'position:fixed;inset:24px;z-index:1000;background:white;overflow:auto;padding:24px';
        root.innerHTML = '<h2></h2><div></div>';
        document.body.append(root);
        Object.defineProperties(modal, {
          modalTitle: { set: value => { root.querySelector('h2').textContent = value; } },
          templateResultContent: { set: value => render(value, root.querySelector('div')) },
        });
        modal.hideModal = () => { root.remove(); modal.dispatchEvent(new Event('typo3-modal-hidden')); };
        modal.modalTitle = options.title;
        modal.templateResultContent = options.content;
        return modal;
      } };`,
    '/typo3/backend/notification.js': 'export default { success() {}, error(...args) { throw new Error(JSON.stringify(args)); } };',
    '/typo3/backend/page-wizard/helper/wizard-helper.js': 'export function openPageWizardModal() {}',
    '/typo3/backend/module.js': 'export const ModuleUtility = { getFromName: () => ({ link: "/module?token=module" }) };',
    '/typo3/backend/viewport.js': 'export default top.viewport;',
    '/typo3/core/document-service.js': 'export default { ready: () => Promise.resolve() };',
    '/typo3/core/lit-helper.js': 'export const lll = key => key;',
  };
  await page.route('https://studio.test/**', async route => {
    const url = new URL(route.request().url());
    const path = url.pathname;
    const javascript = body => route.fulfill({ contentType: 'text/javascript', body });
    if (path in stubs) return javascript(stubs[path]);
    if (path.startsWith('/labels/')) return javascript('export default { get: key => key };');
    if (path.startsWith('/typo3/')) {
      const [, , extension, ...parts] = path.split('/');
      loadedModules.add(`${extension}/${parts.join('/')}`);
      return javascript(await typo3ReleaseModule(version, extension, parts.join('/')));
    }
    const file = path.startsWith('/Backend/') ? `JavaScript${path}` : path.startsWith('/Contrib/') ? path.slice(1) : null;
    if (file) return javascript(await readFile(new URL(file, resources), 'utf8'));
    if (path === '/tree-data' || path === '/tree-filter') {
      requests.push(path);
      // A delayed reload exercises TYPO3 13's void resetFilter() return value.
      if (path === '/tree-data' && requests.filter(value => value === path).length > 1) {
        await new Promise(resolve => setTimeout(resolve, 150));
      }
      return route.fulfill({ json: path === '/tree-filter' ? treeNodes.filter(node => node.identifier !== target) : treeNodes });
    }
    if (path === '/docs') return route.fulfill({ json: { markdown: '# Card\n\nOriginal documentation\n', revision: 'r1', readOnly: false } });
    if (path === '/snapshots') {
      const request = route.request();
      const body = request.headers()['content-type']?.includes('application/json') ? request.postDataJSON()
        : Object.fromEntries(await new Response(request.postDataBuffer(), { headers: request.headers() }).formData());
      snapshotRequests.push(body);
      const identifiers = treeNodes.filter(node => node.nodeType === 'variant').map(node => node.identifier);
      return route.fulfill({ json: { success: true, ...(body.operation === 'discover' ? { catalog: identifiers, identifiers }
        : { result: { identifier: body.scope, status: 'passed' } }) } });
    }
    if (path === '/backend') return route.fulfill({ contentType: 'text/html', body: `${head}
      <style>body{display:flex;margin:0}andersundsehr-frontend-studio-component-tree-container{width:300px;height:800px}.nodes-root{height:650px;overflow:auto}.nodes-list{position:relative}.node{position:absolute;width:100%}.node-content{padding-left:30px}iframe{flex:1;height:800px;border:0}</style>
      <andersundsehr-frontend-studio-component-tree-container></andersundsehr-frontend-studio-component-tree-container>
      <iframe id="content" src="/module?token=module&${query}"></iframe>
      <script type="module">
        window.TYPO3 = { lang: {}, settings: { ajaxUrls: { frontend_studio_component_tree_data: '/tree-data?token=tree', frontend_studio_component_tree_filter: '/tree-filter?token=tree', frontend_studio_snapshot: '/snapshots' } }, ModuleMenu: { App: { getCurrentModule: () => 'admin_frontendstudio' } } };
        window.navigations = []; window.order = []; window.approvals = [];
        window.viewport = { ContentContainer: {
          get: () => document.querySelector('#content').contentWindow,
          getUrl: () => document.querySelector('#content').src,
          setUrl: url => { window.order.push('navigate'); window.navigations.push(String(url)); document.querySelector('#content').src = String(url); }
        } };
        const { ModuleStateStorage } = await import('@typo3/backend/storage/module-state-storage.js');
        ModuleStateStorage.updateWithTreeIdentifier('frontend_studio_component_tree', '${component}', 'site_${component}');
        document.addEventListener('typo3:module-state-storage:update-with-tree-identifier:frontend_studio_component_tree', () => window.order.push('persist'));
        document.addEventListener('frontend-studio:before-navigate', () => {
          window.order.push('confirm');
          window.approvals.push({ searchTerm: window.container.tree.searchTerm, input: document.querySelector('.search-input').value });
        });
        await import('/Backend/component-tree-container.js');
        window.container = document.querySelector('andersundsehr-frontend-studio-component-tree-container');
      </script>` });
    const overview = url.searchParams.has('component');
    return route.fulfill({ contentType: 'text/html', body: `${head}<main ${overview ? 'data-component-overview' : ''} data-frontend-studio-variant-view data-component-identifier="${component}"
      data-selected-site-identifier="preview" data-selected-language-hreflang="de-DE">
      ${overview ? '' : '<header data-frontend-studio-variant-header data-selected-site-identifier="preview" data-selected-language-hreflang="de-DE"></header>'}
      <section data-component-documentation data-component-identifier="${component}" data-doc-uri="/docs">
        <div data-doc-actions><button data-doc-save>Save</button><button data-doc-reset>Reset</button><span data-doc-save-state hidden></span><div data-doc-editor-controls></div></div>
        <div data-overview-description><div data-overview-description-content data-doc-rich></div></div>
        <button data-overview-description-toggle hidden></button><p data-doc-status></p>
      </section>
      ${['Default', 'Mobile:Dark'].map(name => `<a data-overview-variant-link href="/module?componentVariant=${encodeURIComponent(`${component}:${name}`)}">${name}</a>`).join('')}
      </main><script type="module">
        ${overview ? "await import('/Backend/component-overview.js');" : ''}
        window.ready = true;
      </script>` });
  });
  await page.goto('https://studio.test/backend');
  await page.waitForFunction(() => window.container?.treeInitialized && document.querySelector('#content').contentWindow.ready);
  const content = page.frameLocator('#content');
  if (query.startsWith('component=')) await expect(content.locator('[contenteditable="true"]')).toBeVisible();
  // Exercise expansion and its real localStorage persistence when navigating.
  await page.evaluate(() => window.container.tree.nodes.filter(node => node.hasChildren).forEach(node => window.container.tree.hideChildren(node)));
  for (const file of ['tree/tree.js', 'tree/tree-toolbar.js', 'storage/module-state-storage.js', 'storage/client.js']) {
    assert.ok(loadedModules.has(`backend/${file}`), `${file} must use the real TYPO3 ${version} implementation`);
  }
  const search = page.locator('.search-input');
  const state = () => page.evaluate(() => ({
    searchTerm: window.container.tree.searchTerm,
    identifiers: window.container.tree.nodes.map(node => node.identifier),
    selected: window.container.tree.getSelectedNodes().map(node => node.identifier),
    focused: window.container.tree.focusedNode?.identifier,
    stored: JSON.parse(sessionStorage.getItem('t3-module-state-frontend_studio_component_tree')),
    expanded: window.container.tree.nodes.filter(node => node.hasChildren).map(node => node.__expanded),
    order: window.order,
    navigations: window.navigations,
  }));
  const filter = async () => {
    await search.fill('Default');
    await page.waitForFunction(target => window.container.tree.searchTerm === 'Default' && !window.container.tree.loading && !window.container.tree.nodes.some(node => node.identifier === target), target);
    await expect(page.locator(`[role="treeitem"][data-id="${target}"]`)).toHaveCount(0);
    await expect(search).toHaveValue('Default');
  };
  const assertNavigation = async identifier => {
    await page.waitForFunction(identifier => window.navigations.length === 1 && document.querySelector('#content').contentWindow.ready && window.container.tree.focusedNode?.identifier === identifier, identifier);
    const result = await state();
    assert.deepEqual(result.selected, [identifier]);
    assert.deepEqual(result.stored, { identifier, treeIdentifier: `site_${component}_${identifier}` });
    assert.deepEqual(result.expanded, [true, true]);
    assert.deepEqual(result.order, ['confirm', 'persist', 'navigate']);
    const destination = new URL(result.navigations[0]);
    assert.equal(destination.searchParams.get('componentVariant'), identifier);
    assert.equal(destination.searchParams.has('component'), false);
    assert.equal(destination.searchParams.get('site'), 'preview');
    assert.equal(destination.searchParams.get('language'), 'de-DE');
    assert.equal(destination.searchParams.get('token'), 'module');
    await expect(page.locator(`[role="treeitem"][data-id="${identifier}"]`)).toBeVisible();
    const persistedExpansion = await page.evaluate(() => JSON.parse(localStorage.getItem('t3-tree-state-frontend-studio-component-tree')));
    assert.equal(persistedExpansion.site.expanded, true);
    // TYPO3 temporarily expands filtered descendants without changing saved expansion.
    assert.equal(persistedExpansion[`site_${component}`].expanded, !result.searchTerm);
  };
  return { page, content, search, filter, state, requests, snapshotRequests, assertNavigation };
}

for (const version of ['13.4.35', '14.3.7']) {
  for (const filtered of [false, true]) {
    test(`TYPO3 ${version}: Go to Component protects unsaved documentation with a ${filtered ? 'filtered' : 'visible'} target`, async t => {
      const ui = await setup(t, version, filtered ? 'component=site%3Acard' : undefined);
      await ui.page.getByRole('button', { name: 'Test all', exact: true }).click();
      await expect(ui.page.getByRole('status')).toHaveText('2/2 passed');
      await ui.page.evaluate(target => {
        const tests = window.container.tree.snapshots;
        tests.results.set(target, { identifier: target, status: 'failed', message: 'Snapshot mismatch.', expected: '', actual: '' });
        tests.changed();
      }, target);
      if (filtered) await ui.filter();
      const editor = ui.content.locator('[contenteditable="true"]');
      await editor.click();
      await editor.press('End');
      await editor.pressSequentially(' Unsaved snapshot navigation');
      await expect(ui.content.locator('[data-doc-save]')).toBeEnabled();
      const original = await ui.state();
      const originalRequests = [...ui.requests];
      const dialogs = [];
      let approve = false;
      ui.page.on('dialog', async dialog => {
        dialogs.push({ type: dialog.type(), message: dialog.message() });
        if (approve) await dialog.accept(); else await dialog.dismiss();
      });
      const open = async () => {
        await ui.page.getByRole('button', { name: '✕ Results (1)', exact: true }).click();
        await ui.page.getByRole('button', { name: 'Go to Component', exact: true }).click();
      };
      await open();
      await expect.poll(() => dialogs.length).toBe(1);
      assert.deepEqual(dialogs[0], { type: 'confirm', message: 'Discard unsaved documentation changes?' });
      const cancelled = await ui.state();
      assert.deepEqual(cancelled.stored, original.stored);
      assert.deepEqual(cancelled.selected, original.selected);
      assert.equal(cancelled.searchTerm, original.searchTerm);
      assert.deepEqual(cancelled.navigations, []);
      assert.deepEqual(cancelled.order, ['confirm']);
      assert.deepEqual(ui.requests, originalRequests);
      await expect(editor).toContainText('Unsaved snapshot navigation');
      await expect(ui.content.locator('[data-doc-save]')).toBeEnabled();
      await ui.page.evaluate(() => { window.order = []; });
      approve = true;
      await open();
      await ui.assertNavigation(target);
      assert.equal(dialogs.length, 2, 'Navigation and iframe unload must reuse the discard approval.');
    });
  }

  test(`TYPO3 ${version}: only scopes with fixture variants expose snapshot actions`, async t => {
    const treeNodes = [
      ...nodes.map(node => ({ ...node, testable: true })),
      { identifier: 'site:empty.', nodeType: 'folder', name: 'Empty folder', depth: 1, hasChildren: true, testable: false },
      { identifier: 'site:empty.card', nodeType: 'component', name: 'Empty card', depth: 2, hasChildren: false, testable: false },
      { identifier: 'empty:', nodeType: 'namespace', name: 'Empty namespace', depth: 0, hasChildren: true, testable: false },
      { identifier: 'empty:card', nodeType: 'component', name: 'Empty component', depth: 1, hasChildren: false, testable: false },
    ];
    const ui = await setup(t, version, undefined, treeNodes);
    await ui.page.evaluate(() => window.container.tree.nodes.filter(node => node.hasChildren).forEach(node => window.container.tree.showChildren(node)));
    const row = identifier => ui.page.locator(`[role="treeitem"][data-id="${identifier}"]`);
    for (const node of treeNodes.filter(node => !node.testable)) {
      await row(node.identifier).hover();
      await expect(row(node.identifier).getByRole('button', { name: `Test ${node.name}`, exact: true })).toHaveCount(0);
    }
    await row('site:empty.card').hover();
    await expect(row('site:empty.card').getByRole('button', { name: 'Create variant', exact: true })).toBeVisible();
    await expect(row('site:empty.card').getByRole('button', { name: 'Download component folder', exact: true })).toBeVisible();
    await row(component).hover();
    await expect(row(component).getByRole('button', { name: 'Test Card', exact: true })).toBeEnabled();
    await row(`${component}:Default`).hover();
    await expect(row(`${component}:Default`).getByRole('button', { name: 'Test Default', exact: true })).toBeEnabled();
    assert.equal(ui.snapshotRequests.length, 0);
    await ui.page.route('https://studio.test/tree-filter?*', route => route.fulfill({ json: treeNodes.filter(node => ['site', 'site:empty.', 'site:empty.card'].includes(node.identifier)) }));
    await ui.search.fill('Empty');
    await ui.page.waitForFunction(() => window.container.tree.searchTerm === 'Empty' && !window.container.tree.loading && !window.container.tree.nodes.some(node => node.nodeType === 'variant'));
    await row('site').hover();
    await expect(row('site').getByRole('button', { name: 'Test Site', exact: true })).toBeEnabled();
    await row('site').getByRole('button', { name: 'Test Site', exact: true }).click();
    await expect(ui.page.getByRole('status')).toHaveText('2/2 passed');
  });

  test(`TYPO3 ${version}: queued snapshot icons turn blue and rotate only during execution`, async t => {
    const ui = await setup(t, version);
    await ui.page.evaluate(() => {
      const tree = window.container.tree;
      tree.nodes.filter(node => node.hasChildren).forEach(node => tree.showChildren(node));
      window.snapshotCalls = [];
      tree.snapshots.request = body => new Promise(resolve => window.snapshotCalls.push({ body, resolve }));
    });
    const row = identifier => ui.page.locator(`[role="treeitem"][data-id="${identifier}"]`);
    const queued = ui.page.getByRole('img', { name: 'Snapshot tests queued', exact: true });
    const running = ui.page.getByRole('img', { name: 'Snapshot tests running', exact: true });
    const release = (index, payload) => ui.page.evaluate(({ index, payload }) => window.snapshotCalls[index].resolve(payload), { index, payload });
    const identifiers = nodes.filter(node => node.nodeType === 'variant').map(node => node.identifier);
    await row(component).hover();
    await row(component).getByRole('button', { name: 'Test Card', exact: true }).click();
    await expect(queued).toHaveCount(4);
    assert.equal(await queued.first().evaluate(element => getComputedStyle(element).color), 'rgb(0, 120, 168)');
    assert.equal(await queued.first().evaluate(element => element.getBoundingClientRect().width), 24);
    assert.equal(await queued.first().locator('typo3-backend-icon').evaluate(element => getComputedStyle(element).animationName), 'none');
    await release(0, { catalog: identifiers, identifiers });
    await expect(running).toHaveCount(3);
    await expect(row(target).getByRole('img', { name: 'Snapshot tests queued', exact: true })).toBeVisible();
    const spinning = row(identifiers[0]).locator('.snapshot-running');
    await expect(spinning).toHaveAttribute('identifier', 'actions-refresh');
    await expect.poll(() => spinning.evaluate(element => Number(element.getAnimations()[0]?.currentTime ?? 0))).toBeGreaterThan(0);
    assert.equal(await spinning.evaluate(element => getComputedStyle(element).animationIterationCount), 'infinite');
    await ui.page.emulateMedia({ reducedMotion: 'reduce' });
    await expect.poll(() => spinning.evaluate(element => getComputedStyle(element).animationName)).toBe('none');
    await expect(row(identifiers[0]).getByRole('img', { name: 'Snapshot tests running', exact: true })).toBeVisible();
    assert.equal(await spinning.evaluate(element => element.getAnimations().length), 0);
    await ui.page.emulateMedia({ reducedMotion: 'no-preference' });
    await expect.poll(() => spinning.evaluate(element => getComputedStyle(element).animationName)).toBe('frontend-studio-snapshot-spin');
    await release(1, { result: { identifier: identifiers[0], status: 'passed' } });
    await expect(row(identifiers[0]).getByRole('img', { name: 'All tests passed', exact: true })).toBeVisible();
    assert.equal(await row(identifiers[0]).locator('.snapshot-status').evaluate(element => element.getBoundingClientRect().width), 24);
    await expect(row(identifiers[0]).locator('.snapshot-status typo3-backend-icon')).toHaveAttribute('identifier', 'actions-check');
    await expect(row(target).getByRole('img', { name: 'Snapshot tests running', exact: true })).toBeVisible();
    await expect(queued).toHaveCount(0);
    await release(2, { result: { identifier: target, status: 'failed' } });
    await expect(running).toHaveCount(0);
    await expect(row(target).getByRole('button', { name: 'Inspect snapshot failures for Mobile:Dark', exact: true })).toBeVisible();
    assert.equal(await row(target).locator('.snapshot-status').evaluate(element => element.getBoundingClientRect().width), 24);
    await expect(row(target).locator('.snapshot-status typo3-backend-icon')).toHaveAttribute('identifier', 'actions-close');
    await expect(ui.page.getByRole('status')).toHaveText('1/2 passed');
    await ui.page.getByRole('button', { name: 'Test all', exact: true }).click();
    await expect(queued).toHaveCount(4);
    await ui.page.evaluate(() => document.dispatchEvent(new CustomEvent('frontend-studio:component-files-changed')));
    await expect(queued).toHaveCount(0);
    await expect(running).toHaveCount(0);
    await release(3, { catalog: identifiers, identifiers });
    assert.equal(await ui.page.evaluate(() => window.snapshotCalls.length), 4);
    await expect(ui.page.getByRole('status')).toHaveText('0/0 passed');
  });

  for (const view of ['overview', 'variant']) {
    test(`TYPO3 ${version}: Test all uses automatic ${view} context without URL parameters and follows changes`, async t => {
      const ui = await setup(t, version, view === 'overview' ? 'component=site%3Acard' : 'componentVariant=site%3Acard%3ADefault');
      const run = async (site, language) => {
        const start = ui.snapshotRequests.length;
        await ui.page.getByRole('button', { name: 'Test all', exact: true }).click();
        await expect(ui.page.getByRole('status')).toHaveText('2/2 passed');
        assert.deepEqual(ui.snapshotRequests.slice(start), [
          { site, language, scope: '', operation: 'discover' },
          { site, language, scope: `${component}:Default`, operation: 'run' },
          { site, language, scope: target, operation: 'run' },
        ]);
      };
      await run('preview', 'de-DE');
      if (view === 'overview') await ui.content.getByRole('link', { name: 'Default', exact: true }).click();
      await ui.content.locator('[data-frontend-studio-variant-header]').evaluate(header => {
        header.dataset.selectedSiteIdentifier = 'other';
        header.dataset.selectedLanguageHreflang = 'en-GB';
        top.document.dispatchEvent(new CustomEvent('frontend-studio:preview-context-changed'));
      });
      await expect(ui.page.getByRole('status')).toHaveText('0/0 passed');
      await run('other', 'en-GB');
    });
  }

  for (const selected of [true, false]) {
    test(`TYPO3 ${version}: excluded Overview link resets the filter with ${selected ? 'cached selection' : 'an asynchronous reload'}`, async t => {
      const ui = await setup(t, version);
      await ui.filter();
      if (selected) await ui.page.evaluate(() => window.container.tree.selectNode(window.container.tree.nodes.find(node => node.nodeType === 'component'), false));
      else assert.deepEqual((await ui.state()).selected, []);
      await ui.content.getByRole('link', { name: 'Mobile:Dark', exact: true }).click();
      await ui.assertNavigation(target);
      await expect(ui.search).toHaveValue('');
      assert.equal((await ui.state()).searchTerm, '');
      assert.deepEqual((await ui.state()).identifiers, nodes.map(node => node.identifier));
      assert.equal(ui.requests.filter(path => path === '/tree-data').length, selected ? 1 : 2);
      // Refresh must also respect the cleared state, rather than reapplying the old query.
      await ui.page.evaluate(() => window.container.toolbar.refreshTree());
      await ui.page.waitForFunction(() => !window.container.tree.loading);
      assert.equal(ui.requests.filter(path => path === '/tree-filter').length, 1);
    });
  }

  test(`TYPO3 ${version}: cancelling dirty documentation preserves the filter; approving asks once`, async t => {
    const ui = await setup(t, version);
    await ui.filter();
    const editor = ui.content.locator('[contenteditable="true"]');
    await editor.click();
    await editor.press('End');
    await editor.pressSequentially(' Unsaved edit');
    await expect(ui.content.locator('[data-doc-save]')).toBeEnabled();
    const original = await ui.state();
    const originalRequests = [...ui.requests];
    const dialogs = [];
    let approve = false;
    ui.page.on('dialog', async dialog => {
      dialogs.push({ type: dialog.type(), message: dialog.message() });
      if (approve) await dialog.accept(); else await dialog.dismiss();
    });
    const link = ui.content.getByRole('link', { name: 'Mobile:Dark', exact: true });
    await link.click();
    await expect.poll(() => dialogs.length).toBe(1);
    assert.deepEqual(dialogs[0], { type: 'confirm', message: 'Discard unsaved documentation changes?' });
    await expect(ui.search).toHaveValue('Default');
    const cancelled = await ui.state();
    assert.deepEqual({ ...cancelled, order: [] }, original);
    assert.deepEqual(ui.requests, originalRequests);
    await expect(editor).toContainText('Unsaved edit');
    await expect(ui.content.locator('[data-doc-save]')).toBeEnabled();
    await ui.page.evaluate(() => { window.order = []; });
    approve = true;
    await link.click();
    await ui.assertNavigation(target);
    assert.equal(dialogs.length, 2, 'Discard approval must not be requested again during selection or iframe unload');
    await expect(ui.search).toHaveValue('');
    assert.equal((await ui.state()).searchTerm, '');
    assert.deepEqual(await ui.page.evaluate(() => window.approvals), [
      { searchTerm: 'Default', input: 'Default' }, { searchTerm: 'Default', input: 'Default' },
    ], 'Both confirmations must run before the filter is changed');
  });

  test(`TYPO3 ${version}: a variant already in the filtered tree keeps its filter`, async t => {
    const ui = await setup(t, version);
    await ui.filter();
    await ui.content.getByRole('link', { name: 'Default', exact: true }).click();
    await ui.assertNavigation(`${component}:Default`);
    await expect(ui.search).toHaveValue('Default');
    assert.equal((await ui.state()).searchTerm, 'Default');
    assert.equal(ui.requests.filter(path => path === '/tree-data').length, 1);
    await expect(ui.page.locator(`[role="treeitem"][data-id="${target}"]`)).toHaveCount(0);
  });
}
