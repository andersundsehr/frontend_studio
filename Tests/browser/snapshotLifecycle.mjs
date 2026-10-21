import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import test, { before, after } from 'node:test';
import { chromium, expect } from '@playwright/test';
import { typo3ReleaseModule } from './helpers/typo3Release.mjs';

const imports = {
  '@andersundsehr/frontend-studio/backend/': '/Backend/',
  '@typo3/core/ajax/ajax-request.js': '/ajax.js',
  '@typo3/backend/modal.js': '/modal.js',
};
for (const name of ['lit', 'lit-html', 'lit-element', '@lit/reactive-element']) {
  const entry = name === 'lit-html' ? 'lit-html.js' : name === '@lit/reactive-element' ? 'reactive-element.js' : 'index.js';
  imports[name] = `/typo3/${name}/${entry}`;
  imports[`${name}/`] = `/typo3/${name}/`;
}
let browser;
before(async () => { browser = await chromium.launch({ ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) }); });
after(async () => { await browser?.close(); });

async function setup(t, status, readOnly = false) {
  const page = await browser.newPage({ viewport: { width: 1050, height: 850 } });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  t.after(async () => { await page.close(); assert.deepEqual(errors, []); });
  await page.route('https://snapshots.test/**', async route => {
    const path = new URL(route.request().url()).pathname;
    const javascript = body => route.fulfill({ contentType: 'text/javascript', body });
    if (path === '/ajax.js') return javascript('export default class {}');
    if (path === '/modal.js') return javascript(`import { render } from 'lit';
      export default { sizes: { large: 'large' }, advanced(options) {
        const modal = new EventTarget();
        const root = document.createElement('main');
        root.innerHTML = '<h2></h2><div></div>';
        document.body.append(root);
        Object.defineProperties(modal, {
          modalTitle: { set: value => { root.querySelector('h2').textContent = value; } },
          templateResultContent: { set: value => render(value, root.querySelector('div')) },
        });
        modal.modalTitle = options.title;
        modal.templateResultContent = options.content;
        return modal;
      } };`);
    if (path.startsWith('/typo3/')) return javascript(await typo3ReleaseModule('14.3.7', 'core', `Contrib/${path.slice(7)}`));
    if (path.startsWith('/Backend/')) return javascript(await readFile(new URL(`../../Resources/Public/JavaScript${path}`, import.meta.url), 'utf8'));
    return route.fulfill({ contentType: 'text/html', body: `<!doctype html>
      <script type="importmap">${JSON.stringify({ imports })}</script>
      <style>body{font:16px system-ui;padding:24px}main{max-width:960px}button{padding:8px;margin-right:8px}.fs-snapshot-context dd{margin:0}</style>
      <p>Passed variants: <span id="passed">0</span></p>
      <script type="module">
        import SnapshotTests from '/Backend/snapshot-tests.js';
        window.TYPO3 = { settings: { frontendStudio: { snapshotReadOnly: ${readOnly} } } };
        window.calls = []; window.normalStatus = '${status === 'updated' ? 'failed' : 'missing'}';
        window.tests = new SnapshotTests(() => { document.querySelector('#passed').textContent = tests.passed; }, async body => {
          calls.push(body.operation);
          if (body.operation === 'discover') return { identifiers: ['site:card:Default'], catalog: ['site:card:Default'] };
          return { result: { identifier: body.scope, status: body.operation === 'update' ? '${status}' : normalStatus,
            message: 'Rerun the test to verify.', expected: '', actual: '<article>Card</article>' } };
        });
        tests.setContext({ site: 'preview', language: 'de' });
        await tests.run(); await tests.showFailures();
      </script>` });
  });
  await page.goto('https://snapshots.test/');
  return page;
}

for (const status of ['created', 'updated', 'unchanged']) {
  test(`${status}: explicit snapshot action stays yellow and requires a regular test`, async t => {
    const page = await setup(t, status);
    const action = page.getByRole('button', { name: status === 'updated' ? 'Update snapshot' : 'Create snapshot', exact: true });
    await expect(action).toBeEnabled();
    assert.deepEqual(await page.evaluate(() => calls), ['discover', 'run']);
    await action.click();
    await expect(page.getByRole('heading', { name: `Snapshot ${status}`, exact: true })).toBeVisible();
    await expect(page.locator('.fs-snapshot-summary')).toHaveClass(/fs-snapshot-tone-warning/);
    await expect(page.locator('#passed')).toHaveText('0');
    await expect(page.getByRole('button', { name: 'Update snapshot', exact: true })).toBeDisabled();
    assert.deepEqual(await page.evaluate(() => calls), ['discover', 'run', 'update']);
    if (status === 'created') await page.screenshot({ path: fileURLToPath(new URL('../../var/snapshot-created.png', import.meta.url)) });
    await page.evaluate(async () => { normalStatus = 'passed'; await tests.run(); });
    await expect(page.locator('#passed')).toHaveText('1');
  });
}

test('Production disables explicit snapshot creation without sending an update request', async t => {
  const page = await setup(t, 'created', true);
  await expect(page.getByRole('button', { name: 'Create snapshot', exact: true })).toBeDisabled();
  assert.deepEqual(await page.evaluate(() => calls), ['discover', 'run']);
});
