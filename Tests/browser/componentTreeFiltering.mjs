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

async function setup(t, version) {
  const page = await browser.newPage({ viewport: { width: 1100, height: 850 } });
  page.setDefaultTimeout(15000);
  const errors = [];
  const requests = [];
  const loadedModules = new Set();
  const gates = [];
  const server = { nodes: [...nodes], nextFilter: null, nextData: null };
  page.on('pageerror', error => errors.push(error.message));
  t.after(async () => {
    gates.forEach(gate => gate.release());
    await Promise.all(gates.filter(gate => gate.request).map(gate => gate.done));
    await page.close();
    assert.deepEqual(errors, []);
  });
  // Only unrelated backend chrome is stubbed. Tree, TreeToolbar, Lit, debounce,
  // Ajax and both storage APIs come from the pinned TYPO3 release, unchanged.
  const stubs = {
    '/empty.js': '',
    '/typo3/backend/element/icon-element.js': '',
    '/typo3/backend/viewport/content-navigation-toggle.js': '',
    '/typo3/backend/modal.js': 'export default {};',
    '/typo3/backend/notification.js': 'export default { success() {}, error(...args) { top.notifications.push(args); } };',
    '/typo3/backend/page-wizard/helper/wizard-helper.js': 'export function openPageWizardModal() {}',
    '/typo3/backend/module.js': 'export const ModuleUtility = { getFromName: () => { if (top.failModule) throw new Error("Module unavailable"); return { link: "/module?token=module" }; } };',
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
      const responseNodes = path === '/tree-filter' ? server.nodes.filter(node => node.identifier !== target) : [...server.nodes];
      const key = path === '/tree-filter' ? 'nextFilter' : 'nextData';
      const gate = server[key];
      server[key] = null;
      if (gate) {
        gate.received(route.request());
        await gate.wait;
        if (gate.failure === 'abort') await route.abort('failed');
        else if (gate.failure === 'json') await route.fulfill({ contentType: 'application/json', body: '{invalid' });
        else await route.fulfill({ status: gate.failure === 'http' ? 500 : 200, json: responseNodes });
        gate.finished();
        return;
      }
      // A delayed reload exercises TYPO3 13's void resetFilter() return value.
      if (path === '/tree-data' && requests.filter(value => value === path).length > 1) {
        await new Promise(resolve => setTimeout(resolve, 150));
      }
      return route.fulfill({ json: responseNodes });
    }
    if (path === '/docs') return route.fulfill({ json: { markdown: '# Card\n\nOriginal documentation\n', revision: 'r1', readOnly: false } });
    if (path === '/backend') return route.fulfill({ contentType: 'text/html', body: `${head}
      <style>body{display:flex;margin:0}andersundsehr-frontend-studio-component-tree-container{width:300px;height:800px}.nodes-root{height:650px;overflow:auto}.nodes-list{position:relative}.node{position:absolute;width:100%}.node-content{padding-left:30px}iframe{flex:1;height:800px;border:0}</style>
      <andersundsehr-frontend-studio-component-tree-container></andersundsehr-frontend-studio-component-tree-container>
      <iframe id="content" src="/module?token=module&component=site%3Acard&site=preview&language=de-DE"></iframe>
      <script type="module">
        window.TYPO3 = { lang: {}, settings: { ajaxUrls: { frontend_studio_component_tree_data: '/tree-data?token=tree', frontend_studio_component_tree_filter: '/tree-filter?token=tree' } }, ModuleMenu: { App: { getCurrentModule: () => 'admin_frontendstudio' } } };
        window.navigations = []; window.order = []; window.approvals = []; window.notifications = [];
        window.viewport = { ContentContainer: {
          get: () => document.querySelector('#content').contentWindow,
          getUrl: () => document.querySelector('#content').src,
          setUrl: url => {
            if (window.failNavigation === 'sync') throw new Error('Navigation failed');
            if (window.failNavigation === 'async') return Promise.reject(new Error('Navigation rejected'));
            window.order.push('navigate'); window.navigations.push(String(url)); document.querySelector('#content').src = String(url);
            return Promise.resolve();
          }
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
    return route.fulfill({ contentType: 'text/html', body: `${head}<main data-component-overview data-component-identifier="${component}">
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
  await expect(content.locator('[contenteditable="true"]')).toBeVisible();
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
    pendingFilter: window.container.tree.currentFilterRequest !== null,
    loading: window.container.tree.loading,
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
    assert.deepEqual(result.order, ['confirm', 'navigate', 'persist']);
    assert.equal(result.pendingFilter, false);
    assert.equal(result.loading, false);
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
    assert.equal(persistedExpansion[`site_${component}`].expanded, result.searchTerm === '');
  };
  const hold = (key, failure = null) => {
    let received, release, finished;
    const gate = {
      started: new Promise(resolve => { received = resolve; }),
      wait: new Promise(resolve => { release = resolve; }),
      done: new Promise(resolve => { finished = resolve; }),
      received: request => { gate.request = request; received(request); }, release, finished, failure,
    };
    gates.push(gate);
    server[key] = gate;
    return gate;
  };
  const edit = async () => {
    const editor = content.locator('[contenteditable="true"]');
    await editor.click();
    await editor.press('End');
    await editor.pressSequentially(' Unsaved edit');
    await expect(content.locator('[data-doc-save]')).toBeEnabled();
    return editor;
  };
  return { page, content, search, filter, state, requests, assertNavigation, server, hold, edit };
}

async function assertUnloadProtection(ui, dialogs, editor) {
  // Use an actual browser navigation, not just a synthetic unload event. Cancel
  // its native warning and verify that the same edited document remains mounted.
  await ui.page.evaluate(() => { document.querySelector('#content').contentWindow.location.href = '/unrelated'; });
  await expect.poll(() => dialogs.at(-1)?.type).toBe('beforeunload');
  assert.equal(dialogs.filter(dialog => dialog.type === 'confirm').length, 1);
  await expect(editor).toContainText('Unsaved edit');
  await expect(ui.content.locator('[data-doc-save]')).toBeEnabled();
}

function approveDiscard(page) {
  const dialogs = [];
  page.on('dialog', async dialog => {
    dialogs.push({ type: dialog.type(), message: dialog.message() });
    if (dialog.type() === 'confirm') await dialog.accept(); else await dialog.dismiss();
  });
  return dialogs;
}

for (const version of ['13.4.35', '14.3.7']) {
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

  for (const selected of [true, false]) {
    test(`TYPO3 ${version}: a late pending filter cannot overwrite ${selected ? 'cached' : 'reloaded'} nodes or duplicate dirty navigation`, async t => {
      const ui = await setup(t, version);
      await ui.filter();
      if (selected) await ui.page.evaluate(() => window.container.tree.selectNode(window.container.tree.nodes.find(node => node.nodeType === 'component'), false));
      await ui.edit();
      const dialogs = approveDiscard(ui.page);
      const pending = ui.hold('nextFilter');
      await ui.page.evaluate(() => { window.container.tree.filter('Default'); });
      const request = await pending.started;
      await ui.content.getByRole('link', { name: 'Mobile:Dark', exact: true }).click();
      // Deliver stale JSON only after core resetFilter has cleared its term.
      await ui.page.waitForFunction(() => window.container.tree.searchTerm === '');
      pending.release();
      await pending.done;
      await ui.assertNavigation(target);
      await expect(ui.search).toHaveValue('');
      assert.deepEqual((await ui.state()).identifiers, nodes.map(node => node.identifier));
      assert.ok(request.failure(), 'The obsolete request must be aborted through TYPO3 AjaxRequest');
      assert.deepEqual(dialogs.map(dialog => dialog.type), ['confirm']);
    });
  }

  test(`TYPO3 ${version}: overlapping filters cannot hide a pending request from navigation`, async t => {
    const ui = await setup(t, version);
    await ui.filter();
    await ui.page.evaluate(() => window.container.tree.selectNode(window.container.tree.nodes.find(node => node.nodeType === 'component'), false));
    const first = ui.hold('nextFilter');
    await ui.page.evaluate(() => { window.container.tree.filter('Default'); });
    await first.started;
    const second = ui.hold('nextFilter');
    await ui.page.evaluate(() => { window.container.tree.filter('Default'); });
    const request = await second.started;
    await ui.content.getByRole('link', { name: 'Mobile:Dark', exact: true }).click();
    await ui.page.waitForFunction(() => window.container.tree.searchTerm === '');
    first.release();
    second.release();
    await Promise.all([first.done, second.done]);
    await ui.assertNavigation(target);
    assert.ok(request.failure(), 'The second request must remain tracked and be aborted');
    await expect(ui.search).toHaveValue('');
    assert.deepEqual((await ui.state()).identifiers, nodes.map(node => node.identifier));
  });

  for (const failure of ['http', 'abort', 'json']) {
    test(`TYPO3 ${version}: a failed ${failure} filter can finish, retry and navigate without unhandled errors`, async t => {
      const ui = await setup(t, version);
      await ui.filter();
      await ui.edit();
      const dialogs = approveDiscard(ui.page);
      const request = ui.hold('nextFilter', failure);
      await ui.page.evaluate(() => { window.filterAttempt = window.container.tree.filter('Default'); });
      await request.started;
      request.release();
      await request.done;
      await ui.page.evaluate(() => window.filterAttempt);
      assert.equal((await ui.state()).pendingFilter, false);
      assert.equal((await ui.state()).loading, false);
      assert.ok((await ui.page.evaluate(() => window.notifications)).length > 0);
      await ui.page.evaluate(() => window.container.tree.filter('Default'));
      await ui.content.getByRole('link', { name: 'Mobile:Dark', exact: true }).click();
      await ui.assertNavigation(target);
      await expect(ui.search).toHaveValue('');
      assert.deepEqual(dialogs.map(dialog => dialog.type), ['confirm']);
    });
  }

  for (const failure of ['missing', 'renamed', 'http', 'abort', 'module', 'sync', 'async']) {
    test(`TYPO3 ${version}: ${failure} navigation failure revokes discard approval and preserves documentation`, async t => {
      const ui = await setup(t, version);
      await ui.filter();
      const editor = await ui.edit();
      const dialogs = approveDiscard(ui.page);
      const stored = (await ui.state()).stored;
      if (failure === 'missing' || failure === 'renamed') {
        ui.server.nodes = ui.server.nodes.filter(node => node.identifier !== target);
        if (failure === 'renamed') ui.server.nodes.push({ ...nodes.at(-1), identifier: `${component}:Renamed`, name: 'Renamed' });
      }
      const reload = ui.hold('nextData', ['http', 'abort'].includes(failure) ? failure : null);
      await ui.page.evaluate(failure => {
        window.failModule = failure === 'module';
        window.failNavigation = failure;
      }, failure);
      await ui.content.getByRole('link', { name: 'Mobile:Dark', exact: true }).click();
      await reload.started;
      reload.release();
      await reload.done;
      await expect(ui.search).toHaveValue('');
      await ui.page.waitForFunction(() => !window.container.tree.loading && window.container.tree.currentFilterRequest === null);
      const state = await ui.state();
      assert.equal(state.searchTerm, '');
      assert.deepEqual(state.navigations, []);
      assert.deepEqual(state.stored, stored);
      assert.deepEqual(state.order, ['confirm'], 'Failed navigation must not persist its target');
      assert.deepEqual(state.selected, ['http', 'abort'].includes(failure) ? [] : [component]);
      if (failure === 'missing' || failure === 'renamed') assert.ok(!state.identifiers.includes(target));
      await assertUnloadProtection(ui, dialogs, editor);
      if (['http', 'abort', 'module', 'sync', 'async'].includes(failure)) {
        assert.ok((await ui.page.evaluate(() => window.notifications)).length > 0, 'Failures should be reported without unhandled errors');
      }
    });
  }

  test(`TYPO3 ${version}: approval cannot suppress an unrelated unload while reset is pending`, async t => {
    const ui = await setup(t, version);
    await ui.filter();
    const editor = await ui.edit();
    const dialogs = approveDiscard(ui.page);
    const reload = ui.hold('nextData');
    await ui.content.getByRole('link', { name: 'Mobile:Dark', exact: true }).click();
    await reload.started;
    await assertUnloadProtection(ui, dialogs, editor);
    reload.release();
    await reload.done;
    await expect(ui.search).toHaveValue('');
    assert.deepEqual((await ui.state()).navigations, [], 'The unrelated unload must invalidate the pending approval');
    assert.deepEqual((await ui.state()).order, ['confirm']);
    await expect(editor).toContainText('Unsaved edit');
  });
}
