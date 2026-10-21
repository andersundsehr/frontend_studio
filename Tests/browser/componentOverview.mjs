import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test, { before, after } from 'node:test';

import { chromium } from '@playwright/test';

let browser;
before(async () => { browser = await chromium.launch({ ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) }); });
after(async () => { await browser?.close(); });
const resources = new URL('../../Resources/Public/', import.meta.url);
const css = (await Promise.all(['variant-view.css', 'component-markdown.css', 'component-documentation.css', 'component-overview.css'].map(name => readFile(new URL(`Css/Backend/${name}`, resources), 'utf8')))).join('\n');
async function setup(t, { markdown = '# Card\n\nOriginal paragraph\n', production = false, width = 1100 } = {}) {
  const page = await browser.newPage({ viewport: { width, height: 850 } });
  const errors = [];
  const requests = [];
  const assets = [];
  const server = { markdown, revision: 'r1', production };
  page.on('pageerror', error => errors.push(error.message));
  t.after(async () => { assert.deepEqual(errors, []); await page.close(); });
  await page.route('https://studio.test/**', async route => {
    const request = route.request();
    const path = new URL(request.url()).pathname;
    if (path === '/docs') {
      const body = Object.fromEntries(new URLSearchParams(request.postData() || ''));
      requests.push({ method: request.method(), body });
      if (request.method() === 'POST') {
        if (body.revision !== server.revision) return route.fulfill({ status: 409, json: { message: 'Documentation changed on disk.' } });
        server.markdown = body.markdown;
        server.revision = 'r' + (Number(server.revision.slice(1)) + 1);
      }
      return route.fulfill({ json: { markdown: server.markdown, revision: server.revision, readOnly: production } });
    }
    if (path === '/document-service.js') return route.fulfill({ contentType: 'text/javascript', body: 'export default { ready: () => Promise.resolve() };' });
    if (path === '/notification.js') return route.fulfill({ contentType: 'text/javascript', body: 'export default { success() {}, error() {} };' });
    if (path === '/Backend/variant-preview.js') return route.fulfill({ contentType: 'text/javascript', body: 'export default class {}' });
    const file = path.startsWith('/Backend/') ? `JavaScript${path}` : path.startsWith('/Contrib/') ? path.slice(1) : null;
    if (file) {
      assets.push(file);
      return route.fulfill({ contentType: 'text/javascript', body: await readFile(new URL(file, resources), 'utf8') });
    }
    const content = '<div data-overview-description id="overview-description"><div class="frontend-studio-markdown" data-overview-description-content ' + (production ? '' : 'data-doc-rich') + '><pre data-overview-markdown></pre></div></div><button data-overview-description-toggle hidden>Expand documentation</button>';
    const doc = production ? content : `<section class="frontend-studio-documentation" data-component-documentation data-component-identifier="site:card" data-doc-uri="/docs"><div class="frontend-studio-variant-values-actions" data-doc-actions><button data-doc-reset disabled>Reset</button><button data-doc-save disabled>Save</button><span data-doc-save-state hidden>Unsaved changes</span><div data-doc-editor-controls></div></div>${content}<p data-doc-status role="status"></p></section>`;
    await route.fulfill({ contentType: 'text/html', body: `<!doctype html><style>:root{color-scheme:light;--module-docheader-bar-height:0px;--module-docheader-border-width:0px}body{margin:0;font-family:system-ui}${css}</style><script type="importmap">{"imports":{"@typo3/core/document-service.js":"/document-service.js","@typo3/backend/notification.js":"/notification.js","@andersundsehr/frontend-studio/backend/":"/Backend/","@andersundsehr/frontend-studio/vendor/inline-documentation-editor":"/Contrib/inline-documentation-editor.js","@andersundsehr/frontend-studio/vendor/code-highlighting":"/Contrib/code-highlighting.js","@andersundsehr/frontend-studio/vendor/markdown-converter":"/Contrib/markdown-converter.js"}}</script><main class="frontend-studio-overview" data-component-overview data-component-identifier="site:card"><header class="frontend-studio-overview-header"><h1>Card</h1></header>${doc}</main>` });
  });
  await page.goto('https://studio.test/');
  await page.evaluate(async source => {
    document.querySelector('[data-overview-markdown]').textContent = source;
    const { default: Overview } = await import('/Backend/component-overview.js');
    const { getVariantState } = await import('/Backend/variant-state.js');
    window.overview = getVariantState(document.querySelector('main')).features.get(document.querySelector('main'));
  }, markdown);
  if (!production) await page.getByRole('textbox', { name: 'Component documentation rich text' }).waitFor();
  return { page, server, requests, assets };
}

test('the Docs overview uses the persistent editor with Save, Reset and toolbar save shortcuts', async t => {
  const { page, server, requests } = await setup(t);
  await page.evaluate(() => { window.node = document.querySelector('.tiptap'); window.fixtureSaves = 0; document.addEventListener('keydown', e => { if (e.ctrlKey && e.key === 's') window.fixtureSaves++; }); });
  await page.locator('.tiptap p').click();
  assert.equal(await page.evaluate(() => window.node === document.querySelector('.tiptap')), true);
  await page.keyboard.press('End');
  await page.keyboard.type(' edited');
  await page.getByRole('button', { name: 'Reset', exact: true }).click();
  assert.equal(await page.locator('.tiptap p').textContent(), 'Original paragraph');
  await page.locator('.tiptap p').click();
  await page.keyboard.press('End');
  await page.keyboard.type(' saved');
  await page.getByRole('button', { name: 'Text style' }).focus();
  await page.keyboard.press('ControlOrMeta+s');
  await page.waitForFunction(() => document.querySelector('[data-doc-status]').textContent === 'Documentation saved.');
  assert.ok(server.markdown.includes('Original paragraph saved'));
  assert.equal(requests.filter(r => r.method === 'POST').length, 1);
  assert.equal(await page.evaluate(() => window.fixtureSaves), 0);
  assert.equal(await page.locator('[data-doc-save]').isDisabled(), true);
});

test('empty overview documentation can be created without a fixture or an initial write', async t => {
  const { page, server, requests } = await setup(t, { markdown: '', width: 360 });
  assert.equal(requests.filter(r => r.method === 'POST').length, 0);
  await page.locator('.tiptap').click();
  await page.keyboard.type('# New documentation');
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('[data-doc-status]').textContent === 'Documentation saved.');
  assert.equal(server.markdown, '# New documentation');
});

test('Production uses rendered documentation without loading the editor or requesting editable documentation', async t => {
  const { page, assets, requests } = await setup(t, { production: true });
  assert.equal(await page.locator('[data-overview-description] h1').textContent(), 'Card');
  assert.equal(await page.locator('[contenteditable], [data-doc-actions]').count(), 0);
  assert.equal(assets.some(file => file.includes('inline-documentation-editor') || file.includes('markdown-editor') || file.includes('component-documentation')), false);
  assert.equal(requests.length, 0);
});

test('focusing long Docs expands it and external documentation changes preserve unsaved edits', async t => {
  const { page, server } = await setup(t, { markdown: '# Long documentation\n\n' + 'A paragraph.\n\n'.repeat(20) });
  await page.locator('[data-overview-description].is-collapsed').waitFor();
  await page.locator('.tiptap p').first().click();
  assert.equal(await page.locator('[data-overview-description]').evaluate(node => node.classList.contains('is-collapsed')), false);
  await page.keyboard.press('End');
  await page.keyboard.type(' local');
  server.markdown = '# Changed on disk\n'; server.revision = 'r2';
  await page.evaluate(() => top.document.dispatchEvent(new CustomEvent('frontend-studio:component-documentation-changed', { detail: { componentIdentifiers: ['site:card'] } })));
  assert.ok((await page.locator('.tiptap').textContent()).includes(' local'));
  await page.getByRole('button', { name: 'Save', exact: true }).click();
  await page.waitForFunction(() => document.querySelector('[data-doc-status]').textContent === 'Documentation changed on disk.');
  assert.ok((await page.locator('.tiptap').textContent()).includes(' local'));
});

test('short documentation stays expanded when it grows beyond the collapse limit during editing', async t => {
  const { page } = await setup(t, { markdown: 'Short\n' });
  await page.locator('.tiptap').click();
  await page.keyboard.press('End');
  for (let i = 0; i < 12; i++) { await page.keyboard.press('Enter'); await page.keyboard.type('More documentation'); }
  await page.waitForFunction(() => document.querySelector('[data-overview-description-content]').getBoundingClientRect().height > 320);
  assert.equal(await page.locator('[data-overview-description]').evaluate(node => node.classList.contains('is-collapsed')), false);
  await page.getByRole('button', { name: 'Collapse documentation' }).click();
  assert.equal(await page.locator('[data-overview-description]').evaluate(node => node.classList.contains('is-collapsed')), true);
});

test('sticky Docs actions have an opaque theme background and sit at the viewport top', async t => {
  for (const options of [{ theme: 'light', width: 1100, surface: '#fff' }, { theme: 'dark', width: 360, surface: '#131315' }]) {
    await t.test(options.theme, async t => {
      const { page } = await setup(t, { markdown: '# Documentation\n\n' + 'A paragraph.\n\n'.repeat(30), width: options.width });
      await page.evaluate(({ theme, surface }) => {
        document.documentElement.style.colorScheme = theme;
        document.documentElement.style.setProperty('--typo3-surface-container-lowest', surface);
        document.documentElement.style.setProperty('--module-docheader-bar-height', '52px');
        document.documentElement.style.setProperty('--module-docheader-border-width', '1px');
        document.body.style.background = surface;
      }, options);
      await page.locator('.tiptap p').first().click();
      await page.evaluate(() => window.scrollTo(0, 400));
      await page.waitForFunction(() => window.scrollY > 100);
      const row = page.locator('[data-doc-actions]');
      assert.equal(await row.evaluate(node => getComputedStyle(node).backgroundColor), await page.locator('main').evaluate(node => getComputedStyle(node).backgroundColor));
      const bounds = await row.boundingBox();
      assert.ok(Math.abs(bounds.y) < 1, `Sticky row starts at ${bounds.y}px`);
      assert.equal(await row.evaluate(node => {
        const bounds = node.getBoundingClientRect();
        return node.contains(document.elementFromPoint(bounds.left + bounds.width / 2, bounds.top + 1));
      }), true);
    });
  }
});
