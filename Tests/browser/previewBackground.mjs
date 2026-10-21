import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test, { before, after } from 'node:test';

import { chromium } from '@playwright/test';
const resources = new URL('../../Resources/Public/', import.meta.url);
const css = (await Promise.all(['variant-view.css', 'component-overview.css'].map(name => readFile(new URL(`Css/Backend/${name}`, resources), 'utf8')))).join('\n');
const middleware = await readFile(new URL('../../Classes/Middleware/ComponentPreviewMiddleware.php', import.meta.url), 'utf8');
const previewCss = middleware.match(/\$css = <<<'EOF'\n([\s\S]*?)\nEOF;/)[1];
let browser;
before(async () => { browser = await chromium.launch({ ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) }); });
after(async () => { await browser?.close(); });

for (const scheme of ['light', 'dark']) {
  test(`Docs previews share one continuous ${scheme} background through resize, reload and scrolling`, async t => {
    const page = await browser.newPage({ viewport: { width: 1100, height: 900 }, colorScheme: scheme });
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    t.after(async () => { assert.deepEqual(errors, []); await page.close(); });
    await page.route('https://studio.test/**', async route => {
      const url = new URL(route.request().url());
      if (url.pathname === '/__frontendStudio/preview') {
        // The endpoint's own background and a site-level HTML background must
        // both yield to the enclosing Docs frame, while retaining body margins.
        return route.fulfill({ contentType: 'text/html', body: `<!doctype html><style>${previewCss}html{background-color:white}body{min-height:${url.searchParams.get('long') ? 650 : 120}px}</style><body><div id="rendered-component"></div></body>` });
      }
      if (url.pathname === '/document-service.js') return route.fulfill({ contentType: 'text/javascript', body: 'export default { ready: () => Promise.resolve() };' });
      const file = url.pathname.startsWith('/Backend/') ? `JavaScript${url.pathname}` : url.pathname.startsWith('/Contrib/') ? url.pathname.slice(1) : null;
      if (file) return route.fulfill({ contentType: 'text/javascript', body: await readFile(new URL(file, resources), 'utf8') });
      const examples = ['Default', 'Other'].map((name, index) => `<section><div class="frontend-studio-overview-frame${index === 0 ? ' is-first' : ''}" data-overview-frame-container><iframe src="/__frontendStudio/preview?componentVariant=site:card:${name}" data-overview-frame data-variant-identifier="site:card:${name}" ${index === 0 ? 'data-frontend-studio-variant-frame' : 'loading="lazy"'}></iframe></div><p data-overview-frame-status></p></section>`).join('');
      return route.fulfill({ contentType: 'text/html', body: `<!doctype html><style>:root{color-scheme:${scheme};font-size:16px}body{margin:0}${css}</style><script type="importmap">{"imports":{"@typo3/core/document-service.js":"/document-service.js","@andersundsehr/frontend-studio/backend/":"/Backend/","@andersundsehr/frontend-studio/vendor/markdown-converter":"/Contrib/markdown-converter.js","@andersundsehr/frontend-studio/vendor/code-highlighting":"/Contrib/code-highlighting.js"}}</script><main class="frontend-studio-overview" data-component-overview data-preview-uri="/__frontendStudio/preview?componentVariant=site:card:Default">${examples}</main><script type="module">import '/Backend/component-overview.js';</script>` });
    });
    await page.goto('https://studio.test/');
    const frames = page.locator('[data-overview-frame]');
    const containers = page.locator('[data-overview-frame-container]');
    const waitForCanvas = () => page.waitForFunction(() => [...document.querySelectorAll('[data-overview-frame]')].every(frame => frame.contentDocument?.body && frame.contentDocument.documentElement.style.background === 'transparent' && frame.contentDocument.body.style.background === 'transparent' && parseFloat(frame.style.height) > 80));
    const checkPattern = async container => {
      const visible = await container.screenshot();
      await container.locator('iframe').evaluate(frame => { frame.style.visibility = 'hidden'; });
      const backgroundOnly = await container.screenshot();
      await container.locator('iframe').evaluate(frame => { frame.style.removeProperty('visibility'); });
      assert.ok(visible.equals(backgroundOnly), 'An empty preview must not introduce a separate checkerboard or a seam at the iframe boundary');
    };
    for (const width of [1100, 390, 777]) {
      await page.setViewportSize({ width, height: 900 });
      await frames.last().scrollIntoViewIfNeeded();
      await waitForCanvas();
      for (let index = 0; index < 2; index++) {
        await checkPattern(containers.nth(index));
        const { padding, pattern } = await containers.nth(index).evaluate(node => ({ padding: parseFloat(getComputedStyle(node).paddingTop), pattern: getComputedStyle(node).backgroundImage }));
        assert.ok(Math.abs(padding - Math.min(Math.max(0.7 * 16, width * 0.015), 16)) < 0.02);
        assert.ok(pattern.includes(scheme === 'dark' ? 'rgb(221, 221, 221)' : 'rgb(255, 255, 255)'));
      }
    }
    // Exercise the shared live-preview handler, including a newly loaded document.
    await page.evaluate(async () => {
      const { getVariantState } = await import('/Backend/variant-state.js');
      const view = getVariantState(document.querySelector('main'));
      view.previewUri += '&long=1';
      view.changed('context');
    });
    await page.waitForFunction(() => [...document.querySelectorAll('[data-overview-frame]')].every(frame => parseFloat(frame.style.height) >= 650));
    await waitForCanvas();
    await containers.first().evaluate(node => { node.scrollTop = 53; });
    assert.equal(await containers.first().evaluate(node => node.scrollTop), 53);
    await checkPattern(containers.first());
    await page.evaluate(() => document.querySelector('[data-overview-frame-container]').classList.add('is-expanded'));
    await checkPattern(containers.first());

    // Standalone previews keep the endpoint background; only Docs uses a
    // transparent canvas. Compare it with the inspector's surrounding surface.
    await page.goto('https://studio.test/__frontendStudio/preview?componentVariant=site:card:Default');
    const standalone = await page.locator('body').evaluate(node => getComputedStyle(node).backgroundImage);
    assert.ok(standalone.includes(scheme === 'dark' ? 'rgb(221, 221, 221)' : 'rgb(255, 255, 255)'));
    await page.addStyleTag({ content: css });
    assert.equal(await page.evaluate(() => {
      const surface = document.createElement('div');
      surface.className = 'frontend-studio-variant-preview';
      document.body.append(surface);
      return getComputedStyle(surface).backgroundImage;
    }), standalone);
  });
}
