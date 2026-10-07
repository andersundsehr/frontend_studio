import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { chromium } from '@playwright/test';

const resources = new URL('../../Resources/', import.meta.url);
const css = await readFile(new URL('Public/Css/Backend/variant-view.css', resources), 'utf8');
const type = 'Vendor\\Extension\\Domain\\Model\\VeryLongArgumentType|Vendor\\Extension\\Domain\\Model\\AnotherType';
const browser = await chromium.launch({ ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) });
try {
  const page = await browser.newPage({ viewport: { width: 700, height: 600 } });
  const errors = [];
  page.on('pageerror', error => { errors.push(error.message); console.error(error.message); });
  await page.route('https://studio.test/**', async route => {
    const path = new URL(route.request().url()).pathname;
    if (path.startsWith('/modules/')) {
      return route.fulfill({ contentType: 'text/javascript', body: await readFile(new URL(`Public/JavaScript/Backend/${path.split('/').at(-1)}`, resources), 'utf8') });
    }
    if (path === '/document.js') return route.fulfill({ contentType: 'text/javascript', body: 'export default {ready:async()=>{}};' });
    if (path.endsWith('.js')) return route.fulfill({ contentType: 'text/javascript', body: 'export default {};' });
    return route.fulfill({ contentType: 'text/html', body: `<!doctype html><style>
      *{box-sizing:border-box}body{margin:0;padding:16px}:root{--typo3-component-border-color:#aaa;--typo3-surface-container-low:#eee;--typo3-surface-container-high:#fff;--typo3-text-color-base:#222}${css}
      main{width:100%;overflow:hidden}button{font:inherit}
      </style><script type="importmap">{"imports":{"@andersundsehr/frontend-studio/backend/":"/modules/","@typo3/core/ajax/ajax-request.js":"/ajax.js","@typo3/backend/notification.js":"/notification.js","@typo3/core/document-service.js":"/document.js"}}</script>
      <main><div data-controls><div class="frontend-studio-control-row" data-control-row>
        <div class="frontend-studio-control-label">Argument <span class="frontend-studio-control-type" data-control-type>
          <button type="button" class="frontend-studio-control-type-trigger" aria-describedby="full-type"><code>VeryLongArgumentType|AnotherType</code></button>
          <span id="full-type" class="frontend-studio-control-type-popover" role="tooltip" popover="auto"><code>${type}</code></span>
        </span></div>
        <details class="frontend-studio-transformer-inputs" data-transformer-group>
          <summary class="frontend-studio-transformer-summary"><span data-transformer-summary></span></summary>
          <div class="frontend-studio-control-row"><label>Nested input</label><input value="Nested value" data-frontend-studio-variant-value data-fixture-name="argument.value" data-fixture-type="string"></div>
        </details>
      </div><p id="neighbor">Following content</p></div></main>
      <script type="module">import Controls from '/modules/variant-controls.js';const root=document.querySelector('[data-controls]');window.controls=new Controls(root,{root,changed(){}});await controls.ready;window.ready=true;</script>` });
  });
  await page.goto('https://studio.test/');
  await page.waitForFunction(() => window.ready);
  const button = page.locator('[data-control-type] button');
  const popover = page.locator('[popover]');
  const neighbor = page.locator('#neighbor');
  const before = await neighbor.boundingBox();
  await button.hover();
  await popover.waitFor({ state: 'visible' });
  assert.deepEqual(await neighbor.boundingBox(), before, 'opening must not change layout');
  assert.equal(await popover.textContent(), type);
  await popover.hover();
  await page.waitForTimeout(200);
  assert.equal(await popover.isVisible(), true, 'pointer can move from trigger into popup');
  const firstLine = await popover.locator('code').evaluate(element => element.getClientRects()[0].toJSON());
  await page.mouse.move(firstLine.x + 2, firstLine.y + firstLine.height / 2);
  await page.mouse.down();
  await page.mouse.move(firstLine.right - 2, firstLine.y + firstLine.height / 2, { steps: 10 });
  await page.mouse.up();
  assert.ok(await page.evaluate(() => window.getSelection().toString().length > 0), 'text can be selected by dragging');
  const selected = await popover.evaluate(element => {
    const range = document.createRange();range.selectNodeContents(element);
    const selection = window.getSelection();selection.removeAllRanges();selection.addRange(range);
    return { text: selection.toString(), selectable: getComputedStyle(element).userSelect };
  });
  assert.equal(selected.text, type);
  assert.equal(selected.selectable, 'text');
  await page.keyboard.press('Escape');
  await popover.waitFor({ state: 'hidden' });
  await page.mouse.move(690, 590);
  await button.focus();
  await popover.waitFor({ state: 'visible' });
  await page.keyboard.press('Escape');
  await popover.waitFor({ state: 'hidden' });
  assert.equal(await button.evaluate(element => element === document.activeElement), true);
  await page.keyboard.press('Tab');
  await button.hover();
  await popover.waitFor({ state: 'visible' });
  await page.mouse.move(690, 590);
  await popover.waitFor({ state: 'hidden' });
  await page.setViewportSize({ width: 320, height: 480 });
  await button.click();
  await popover.waitFor({ state: 'visible' });
  const bounds = await popover.boundingBox();
  assert.ok(bounds.x >= 8 && bounds.x + bounds.width <= 312);
  assert.ok(bounds.y >= 8 && bounds.y + bounds.height <= 472);
  await page.mouse.click(1, 1);
  await popover.waitFor({ state: 'hidden' });
  await page.locator('summary').click();
  const group = await page.locator('details').evaluate(element => ({ open: element.open, border: getComputedStyle(element).borderInlineStartWidth, padding: getComputedStyle(element).paddingInlineStart, background: getComputedStyle(element).backgroundColor }));
  assert.equal(group.open, true);
  assert.equal(group.border, '3px');
  assert.notEqual(group.padding, '0px');
  assert.notEqual(group.background, 'rgba(0, 0, 0, 0)');
  await button.hover();
  await popover.waitFor({ state: 'visible' });
  await page.locator('main').evaluate(element => {
    element.style.height = '80px';element.style.overflow = 'auto';element.scrollTop = 30;
  });
  await popover.waitFor({ state: 'hidden' });
  // Restore the fixture before the independent teardown check. Playwright's
  // auto-scroll otherwise races with the intentional scroll-to-close handler.
  await page.locator('main').evaluate(async element => {
    element.style.height = 'auto';element.style.overflow = 'hidden';element.scrollTop = 0;
    await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
  });
  await page.mouse.move(1, 479);
  await button.hover();
  await popover.waitFor({ state: 'visible' });
  await page.evaluate(() => controls.destroy());
  await popover.waitFor({ state: 'hidden' });
  assert.deepEqual(errors, []);
} finally { await browser.close(); }
