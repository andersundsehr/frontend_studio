import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { chromium } from '@playwright/test';

// Exercise the shipped controls, CSS and ES modules in a real browser. Only the
// surrounding TYPO3 chrome/storage and the rendered component response are fixtures.
const resources = new URL('../../Resources/', import.meta.url);
const header = await readFile(new URL('Private/Components/Variant/Header/Header.fluid.html', resources), 'utf8');
const controls = header.match(/<div[^>]+aria-label="Preview viewport">[\s\S]*?<\/div>/)[0];
const css = await readFile(new URL('Public/Css/Backend/variant-view.css', resources), 'utf8');
const browser = await chromium.launch({
  ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}),
});
try {
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.route('https://studio.test/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.startsWith('/modules/')) {
      return route.fulfill({ contentType: 'text/javascript', body: await readFile(new URL(`Public/JavaScript/Backend/${path.split('/').at(-1)}`, resources), 'utf8') });
    }
    if (path === '/storage.js') {
      return route.fulfill({ contentType: 'text/javascript', body: 'export default {set: async () => {}};' });
    }
    if (path === '/preview') {
      return route.fulfill({ contentType: 'text/html', body: '<style>body {margin:0} @media (max-width: 400px) {body {background:rgb(0, 128, 0)}}</style><h1>Preview component</h1>' });
    }
    return route.fulfill({ contentType: 'text/html', body: `<!doctype html>
      <style>body{margin:0} *{box-sizing:border-box} :root{--typo3-surface-base:#eee;--typo3-surface-container-lowest:#fff;--typo3-component-border-color:#ccc} label{display:inline-block} input{width:6em} ${css}</style>
      <script type="importmap">{"imports":{"@andersundsehr/frontend-studio/backend/":"/modules/","@typo3/backend/storage/persistent.js":"/storage.js"}}</script>
      <div class="frontend-studio-variant-view" data-frontend-studio-variant-view>
        <div class="frontend-studio-variant-workspace">
          <div class="frontend-studio-variant-preview-column">
            <header class="frontend-studio-variant-header">${controls}</header>
            <div class="frontend-studio-variant-preview"><iframe title="Preview" data-frontend-studio-variant-frame src="/preview"></iframe></div>
          </div>
          <div class="frontend-studio-variant-sidebar-resize-handle" data-frontend-studio-variant-sidebar-resize role="separator" aria-label="Resize sidebar"></div>
          <aside class="frontend-studio-variant-sidebar">Fixture sidebar</aside>
        </div>
      </div>
      <script type="module">
        import Preview from '/modules/variant-preview.js';
        import Resize from '/modules/variant-sidebar-resize.js';
        const root = document.querySelector('[data-frontend-studio-variant-view]');
        window.view = Object.assign(new EventTarget(), {buildPreviewUrl: () => new URL('/preview', location.href)});
        window.preview = new Preview(root, view);
        window.resize = new Resize(root, view);
        resize.initialize();
        window.ready = true;
      </script>` });
  });
  await page.goto('https://studio.test/');
  await page.waitForFunction(() => window.ready);
  const select = page.getByRole('combobox', { name: 'Preview viewport' });
  const frame = page.locator('iframe');
  const reset = page.getByRole('button', { name: 'Reset viewport' });
  const sidebar = page.locator('aside');
  const initialSidebar = await sidebar.boundingBox();
  const size = () => frame.evaluate((el) => [el.contentWindow.innerWidth, el.contentWindow.innerHeight]);
  const width = page.getByLabel('Width px', { exact: true });
  const height = page.getByLabel('Height px', { exact: true });
  assert.equal(await reset.isDisabled(), true);
  await select.focus();
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('Enter');
  assert.equal(await select.inputValue(), 'mobile');
  assert.deepEqual(await size(), [375, 667]);
  assert.equal(await frame.evaluate((el) => el.contentWindow.getComputedStyle(el.contentDocument.body).backgroundColor), 'rgb(0, 128, 0)');
  for (const [preset, expected] of [['tablet', [768, 1024]], ['desktop', [1280, 800]]]) {
    await select.selectOption(preset);
    assert.deepEqual(await size(), expected);
    assert.deepEqual(await sidebar.boundingBox(), initialSidebar);
  }
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth === innerWidth), true);
  await select.selectOption('custom');
  await width.fill('420');
  await height.fill('640');
  assert.deepEqual(await size(), [420, 640]);
  for (const invalid of ['', '0', '-1', '10001', '1.5']) {
    await width.fill(invalid);
    assert.equal(await width.evaluate((el) => el.checkValidity()), false);
    assert.deepEqual(await size(), [420, 640]);
  }
  await width.fill('420');
  const loaded = page.waitForEvent('framenavigated', (f) => f.parentFrame() !== null);
  await page.evaluate(() => view.dispatchEvent(new Event('files')));
  await loaded;
  assert.deepEqual(await size(), [420, 640]);
  const handle = await page.getByRole('separator').boundingBox();
  await page.mouse.move(handle.x + 4, handle.y + 30);
  await page.mouse.down();
  await page.mouse.move(handle.x - 80, handle.y + 30);
  await page.mouse.up();
  assert.ok((await sidebar.boundingBox()).width > initialSidebar.width);
  assert.deepEqual(await size(), [420, 640]);
  await reset.click();
  assert.equal(await select.inputValue(), 'responsive');
  assert.equal(await width.isHidden(), true);
  assert.equal(await frame.evaluate((el) => el.style.width), '');
  assert.equal(await frame.evaluate((el) => el.clientWidth === el.parentElement.clientWidth), true);
  await page.setViewportSize({ width: 800, height: 900 });
  const stackedSidebar = await sidebar.boundingBox();
  await select.selectOption('desktop');
  assert.deepEqual(await size(), [1280, 800]);
  assert.deepEqual(await sidebar.boundingBox(), stackedSidebar);
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth === innerWidth), true);
  await page.evaluate(() => preview.destroy());
  const beforeDestroy = await size();
  await select.selectOption('mobile');
  assert.deepEqual(await size(), beforeDestroy, 'destroy removes control listeners');
  assert.deepEqual(errors, []);
  console.log('PASS: real iframe dimensions/media query, presets, custom/invalid inputs, keyboard selection, reset, reload, desktop/stacked sidebar and drag, overflow, lifecycle.');
} finally {
  await browser.close();
}
