import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test, { before, after } from 'node:test';

import { chromium } from '@playwright/test';

let browser;
before(async () => { browser = await chromium.launch({ ...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}) }); });
after(async () => { await browser?.close(); });
const resources = new URL('../../Resources/Public/', import.meta.url);
const styles = (await Promise.all(['variant-view.css', 'component-markdown.css', 'component-documentation.css'].map(name => readFile(new URL(`Css/Backend/${name}`, resources), 'utf8')))).join('\n');
const fluidFixtures = JSON.parse(await readFile(new URL('../Unit/Service/Fixtures/fluid-highlighting.json', import.meta.url), 'utf8'));
async function setup(t, markdown = '# Card\n\nUse this component for **project updates**.\n', { inspector = false, dark = false, width = 1100 } = {}) {
  const page = await browser.newPage({ viewport: { width, height: 850 } });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  t.after(async () => { assert.deepEqual(errors, []); await page.close(); });
  await page.route('https://studio.test/**', async route => {
    const path = new URL(route.request().url()).pathname;
    const files = { '/editor.js': 'JavaScript/Backend/markdown-editor.js', '/renderer.js': 'JavaScript/Backend/markdown-renderer.js', '/converter.js': 'Contrib/markdown-converter.js', '/tiptap.js': 'Contrib/inline-documentation-editor.js', '/highlighting.js': 'Contrib/code-highlighting.js' };
    if (files[path]) return route.fulfill({ contentType: 'text/javascript', body: await readFile(new URL(files[path], resources), 'utf8') });
    const content = '<div class="frontend-studio-markdown" id="root" data-doc-rich></div><div class="frontend-studio-markdown" id="reference"></div>';
    const fixture = inspector ? `<main class="frontend-studio-variant-view"><aside class="frontend-studio-variant-sidebar" style="width:100%;max-width:none;flex:1"><div class="frontend-studio-variant-sidebar-body"><section class="frontend-studio-documentation"><div class="frontend-studio-variant-values-actions" data-doc-actions><button>Reset</button><button>Save</button><span class="frontend-studio-variant-save-state" data-doc-save-state>Unsaved changes</span><div data-doc-editor-controls></div></div>${content}</section></div></aside></main>` : `<main class="frontend-studio-documentation">${content}</main>`;
    await route.fulfill({ contentType: 'text/html', body: `<!doctype html><style>:root{color-scheme:${dark ? 'dark' : 'light'};--module-docheader-bar-height:0px;--module-docheader-border-width:0px;--typo3-text-color-base:${dark ? '#eee' : '#1f2328'};--typo3-surface-base:${dark ? '#131315' : '#fff'};--typo3-surface-container-lowest:var(--typo3-surface-base);--typo3-surface-container-low:${dark ? '#222' : '#f6f8fa'};--typo3-component-border-color:${dark ? '#444' : '#d8dee4'}}body{margin:0;font-family:system-ui;color:var(--typo3-text-color-base);background:var(--typo3-surface-base)}main{margin:${inspector ? '0' : '70px 80px'};width:${inspector ? '100%' : '880px'}}[data-doc-actions] button{font:inherit;line-height:1.25;padding:.35rem .6rem;color:inherit;background:var(--typo3-surface-container-low);border:1px solid var(--typo3-component-border-color);border-radius:.35rem}${styles}</style><script type="importmap">{"imports":{"@andersundsehr/frontend-studio/vendor/inline-documentation-editor":"/tiptap.js","@andersundsehr/frontend-studio/vendor/markdown-converter":"/converter.js","@andersundsehr/frontend-studio/vendor/code-highlighting":"/highlighting.js"}}</script>${fixture}` });
  });
  await page.goto('https://studio.test/');
  await page.evaluate(async source => {
    const { createEditor } = await import('/editor.js');
    const { renderMarkdown } = await import('/renderer.js');
    window.changes = [];
    window.ui = await createEditor(document.querySelector('#root'), source, value => window.changes.push(value), document.querySelector('[data-doc-editor-controls]'));
    document.querySelector('#reference').innerHTML = renderMarkdown(source);
  }, markdown);
  return page;
}

test('the mounted editor matches rendered Docs typography and focusing never replaces or moves content', async t => {
  const page = await setup(t);
  const initial = await page.evaluate(() => {
    window.originalNode = document.querySelector('.tiptap');
    const node = document.querySelector('#root h1');
    const rect = node.getBoundingClientRect();
    return { top: rect.top, height: rect.height, html: window.originalNode.innerHTML, changes: window.changes.length };
  });
  assert.equal(initial.changes, 0);
  await page.mouse.move(0, 0);
  assert.equal(await page.locator('.frontend-studio-editor-controls').evaluate(node => getComputedStyle(node).visibility), 'hidden');
  await page.locator('.tiptap').click();
  const focused = await page.evaluate(() => {
    const node = document.querySelector('#root h1');
    const rect = node.getBoundingClientRect();
    return { same: window.originalNode === document.querySelector('.tiptap'), top: rect.top, height: rect.height, html: window.originalNode.innerHTML, changes: window.changes.length };
  });
  assert.equal(focused.same, true);
  assert.deepEqual({ ...focused, same: undefined }, { ...initial, same: undefined });
  assert.equal(await page.locator('.frontend-studio-editor-controls').evaluate(node => getComputedStyle(node).visibility), 'visible');
  assert.equal(await page.locator('.frontend-studio-editor-bubble').isVisible(), true);
  const typography = await page.evaluate(() => ['h1', 'p', 'strong'].map(tag => {
    const properties = ['fontSize', 'lineHeight', 'fontWeight', 'marginTop', 'marginBottom', 'borderBottomWidth', 'color'];
    const get = parent => { const style = getComputedStyle(document.querySelector(`${parent} ${tag}`)); return properties.map(property => style[property]); };
    return { editor: get('#root .tiptap'), preview: get('#reference') };
  }));
  for (const styles of typography) assert.deepEqual(styles.editor, styles.preview);
});

test('Save, Reset and the source toggle share a hover/focus row and the bubble fits narrow panels without moving text', async t => {
  for (const options of [{ width: 751, dark: true }, { width: 360, dark: false }]) {
    await t.test(`${options.width}px ${options.dark ? 'dark' : 'light'}`, async t => {
      const page = await setup(t, '# Test\n\nDocumentation\n', { inspector: true, ...options });
      await page.mouse.move(0, 0);
      const heading = page.locator('#root h1');
      const initial = await heading.boundingBox();
      assert.equal(await page.locator('[data-doc-actions]').evaluate(node => getComputedStyle(node).visibility), 'hidden');
      await heading.hover();
      assert.deepEqual(await heading.boundingBox(), initial);
      const controls = await page.locator('[data-doc-actions] button').evaluateAll(buttons => {
        const actions = document.querySelector('[data-doc-actions]').getBoundingClientRect();
        const panel = document.querySelector('.frontend-studio-documentation').getBoundingClientRect();
        return buttons.map(button => {
          const rect = button.getBoundingClientRect();
          const target = document.elementFromPoint(rect.left + rect.width / 2, rect.top + 2);
          return { label: button.getAttribute('aria-label') || button.textContent, top: rect.top, insidePanel: rect.left >= panel.left && rect.right <= panel.right, uncovered: button === target || button.contains(target) };
        });
      });
      assert.deepEqual(controls.map(({ top, ...rest }) => rest), ['Reset', 'Save', 'Edit Markdown source'].map(label => ({ label, insidePanel: true, uncovered: true })));
      assert.ok(Math.max(...controls.map(c => c.top)) - Math.min(...controls.map(c => c.top)) < 3);
      const saveState = await page.locator('[data-doc-save-state]').boundingBox();
      assert.ok(saveState.y > Math.max(...controls.map(c => c.top)));
      assert.equal(await page.getByRole('button', { name: 'Insert', exact: true }).count(), 0);
      await heading.click();
      assert.deepEqual(await heading.boundingBox(), initial);
      await page.getByRole('button', { name: 'Text style' }).waitFor({ state: 'visible' });
      const bubble = await page.getByRole('toolbar', { name: 'Text formatting' }).boundingBox();
      assert.ok(bubble.x >= 8 && bubble.x + bubble.width <= options.width - 8);
      const icons = await page.locator('.frontend-studio-editor-row button:not([role])').evaluateAll(buttons => buttons.every(button => button.querySelector('svg[aria-hidden="true"]') && button.title === button.getAttribute('aria-label') && (button.getAttribute('aria-label') === 'Text style' || !button.textContent)));
      assert.equal(icons, true);
      const rows = page.locator('.frontend-studio-editor-row');
      assert.deepEqual(await rows.nth(0).locator('button:not([role])').evaluateAll(buttons => buttons.map(button => button.getAttribute('aria-label'))), ['Text style', 'Bold', 'Italic', 'Strike', 'Code', 'Code block', 'Lists', 'Quote', 'Divider']);
      assert.deepEqual(await rows.nth(1).locator('button').evaluateAll(buttons => buttons.map(button => button.getAttribute('aria-label'))), ['Link', 'Image', 'Undo', 'Redo']);
      const firstRow = await rows.nth(0).boundingBox();
      const secondRow = await rows.nth(1).boundingBox();
      assert.ok(secondRow.y >= firstRow.y + firstRow.height);
      const code = await page.getByRole('button', { name: 'Code', exact: true }).boundingBox();
      const codeBlock = await page.getByRole('button', { name: 'Code block', exact: true }).boundingBox();
      assert.equal(codeBlock.y, code.y);
      assert.ok(codeBlock.x > code.x + code.width);
      await page.mouse.move(0, 0);
      assert.equal(await page.getByRole('toolbar').evaluate(node => getComputedStyle(node).opacity), '0.5');
      await page.getByRole('button', { name: 'Text style' }).hover();
      assert.equal(await page.getByRole('toolbar').evaluate(node => getComputedStyle(node).opacity), '1');
      await page.keyboard.press('Escape');
      const richIcon = await page.getByRole('button', { name: 'Edit Markdown source' }).innerHTML();
      await page.getByRole('button', { name: 'Edit Markdown source' }).click();
      assert.equal(await page.getByRole('textbox', { name: 'Component documentation Markdown' }).isVisible(), true);
      const sourceButton = page.getByRole('button', { name: 'Switch to rich text' });
      assert.notEqual(await sourceButton.innerHTML(), richIcon);
      assert.equal(await sourceButton.getAttribute('aria-pressed'), 'true');
      await sourceButton.click();
      assert.equal(await page.getByRole('button', { name: 'Edit Markdown source' }).innerHTML(), richIcon);
      assert.equal(await page.getByRole('button', { name: 'Edit Markdown source' }).getAttribute('aria-pressed'), 'false');
    });
  }
});

test('the style menu applies PhpStorm names to Paragraph and H1–H6 and follows the current selection', async t => {
  const page = await setup(t, 'Example\n');
  await page.locator('.tiptap').click();
  const trigger = page.getByRole('button', { name: 'Text style' });
  const names = ['Normal', 'Title', 'Subtitle', 'Heading 1', 'Heading 2', 'Heading 3', 'Heading 4'];
  assert.equal(await trigger.textContent(), 'Normal');
  for (const level of [1, 2, 3, 4, 5, 6, 0]) {
    await trigger.click();
    const menu = page.getByRole('menu', { name: 'Text style' });
    assert.deepEqual(await menu.getByRole('menuitemradio').evaluateAll(nodes => nodes.map(node => node.getAttribute('aria-label'))), names);
    assert.equal(await menu.getByRole('separator').count(), 2);
    const previous = page.getByRole('menuitemradio', { name: await trigger.textContent(), exact: true });
    assert.equal(await previous.getAttribute('aria-checked'), 'true');
    assert.equal(await previous.locator('svg').evaluate(node => getComputedStyle(node).visibility), 'visible');
    const item = page.getByRole('menuitemradio', { name: names[level], exact: true });
    assert.equal(await item.locator('.frontend-studio-editor-style-hint').textContent(), level ? `(H${level})` : '');
    await item.click();
    assert.equal(await page.locator(`#root .tiptap > ${level ? `h${level}` : 'p'}`).textContent(), 'Example');
    assert.equal(await page.evaluate(() => window.ui.getData()), (level ? '#'.repeat(level) + ' ' : '') + 'Example');
    assert.equal(await trigger.textContent(), names[level]);
    assert.equal(await trigger.getAttribute('aria-expanded'), 'false');
    assert.equal(await page.getByRole('toolbar').isVisible(), true);
  }
  await page.evaluate(() => window.ui.setData('# First\n\n## Second\n\nThird\n'));
  for (const [selector, name] of [['h1', 'Title'], ['h2', 'Subtitle'], ['p', 'Normal']]) {
    await page.locator(`#root .tiptap > ${selector}`).click();
    await trigger.filter({ hasText: name }).waitFor();
    assert.equal(await trigger.textContent(), name);
  }
});

test('style-menu keyboard navigation keeps the bubble and selection, and Escape dismisses only the menu first', async t => {
  const page = await setup(t, 'Example\n');
  await page.locator('.tiptap').click();
  const trigger = page.getByRole('button', { name: 'Text style' });
  await trigger.click();
  assert.equal(await page.getByRole('toolbar').isVisible(), true);
  assert.equal(await page.getByRole('menuitemradio', { name: 'Normal', exact: true }).evaluate(node => node === document.activeElement), true);
  assert.deepEqual(await page.evaluate(() => window.changes), []);
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('Enter');
  assert.equal(await page.locator('#root h1').textContent(), 'Example');
  assert.equal(await page.locator('.tiptap').evaluate(node => node === document.activeElement), true);
  assert.equal(await page.getByRole('toolbar').isVisible(), true);
  await page.keyboard.press('Tab');
  assert.equal(await trigger.evaluate(node => node === document.activeElement), true);
  await page.keyboard.press('Tab');
  assert.equal(await page.getByRole('button', { name: 'Bold', exact: true }).evaluate(node => node === document.activeElement), true);
  await page.keyboard.press('Shift+Tab');
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('End');
  assert.equal(await page.getByRole('menuitemradio', { name: 'Heading 4', exact: true }).evaluate(node => node === document.activeElement), true);
  await page.keyboard.press('Home');
  assert.equal(await page.getByRole('menuitemradio', { name: 'Normal', exact: true }).evaluate(node => node === document.activeElement), true);
  await page.keyboard.press('Escape');
  assert.equal(await trigger.getAttribute('aria-expanded'), 'false');
  assert.equal(await trigger.evaluate(node => node === document.activeElement), true);
  assert.equal(await page.getByRole('toolbar').isVisible(), true);
  await trigger.press('ArrowUp');
  await page.keyboard.press('Tab');
  assert.equal(await trigger.getAttribute('aria-expanded'), 'false');
  assert.equal(await page.getByRole('button', { name: 'Bold', exact: true }).evaluate(node => node === document.activeElement), true);
  await trigger.click();
  await page.locator('#reference p').click();
  assert.equal(await page.getByRole('toolbar').isVisible(), false);
  assert.equal(await page.getByRole('button', { name: 'Text style', includeHidden: true }).getAttribute('aria-expanded'), 'false');
});

test('the Lists menu preserves a multi-paragraph selection and marks bullet and numbered lists', async t => {
  const page = await setup(t, 'First\n\nSecond\n');
  await page.locator('.tiptap').click();
  await page.keyboard.press('ControlOrMeta+a');
  const trigger = page.getByRole('button', { name: 'Lists', exact: true });
  await trigger.click();
  const menu = page.getByRole('menu', { name: 'Lists', exact: true });
  assert.deepEqual(await menu.getByRole('menuitemradio').evaluateAll(nodes => nodes.map(node => node.getAttribute('aria-label'))), ['Bullet list', 'Numbered list']);
  await page.getByRole('menuitemradio', { name: 'Bullet list', exact: true }).click();
  assert.deepEqual(await page.locator('#root ul li').allTextContents(), ['First', 'Second']);
  assert.equal(await trigger.getAttribute('aria-pressed'), 'true');
  await trigger.click();
  assert.equal(await page.getByRole('menuitemradio', { name: 'Bullet list', exact: true }).getAttribute('aria-checked'), 'true');
  await page.keyboard.press('ArrowRight');
  await page.keyboard.press('Enter');
  assert.deepEqual(await page.locator('#root ol li').allTextContents(), ['First', 'Second']);
  assert.equal(await page.locator('#root ul').count(), 0);
  await trigger.press('ArrowDown');
  assert.equal(await page.getByRole('menuitemradio', { name: 'Numbered list', exact: true }).getAttribute('aria-checked'), 'true');
  await page.keyboard.press('Enter');
  assert.equal(await page.locator('#root ol').count(), 0);
  assert.deepEqual(await page.locator('#root .tiptap > p').allTextContents(), ['First', 'Second']);
  assert.equal(await trigger.getAttribute('aria-pressed'), 'false');
});

test('format menus stay inside narrow viewports in both themes and close on source switching and Reset', async t => {
  for (const dark of [false, true]) {
    await t.test(dark ? 'dark' : 'light', async t => {
      const page = await setup(t, '# Test\n\nDocumentation\n', { inspector: true, width: 360, dark });
      await page.locator('#root h1').click();
      assert.equal(await page.getByRole('button', { name: 'Lists', exact: true }).isDisabled(), true);
      await page.keyboard.press('Escape');
      await page.locator('#root .tiptap > p').click();
      for (const name of ['Text style', 'Lists']) {
        await page.getByRole('button', { name, exact: true }).click();
        const menu = page.getByRole('menu', { name, exact: true });
        const bounds = await menu.boundingBox();
        const toolbar = await page.getByRole('toolbar').boundingBox();
        assert.ok(bounds.x >= 8 && bounds.x + bounds.width <= 352);
        assert.ok(bounds.y >= 8 && bounds.y + bounds.height <= 842);
        assert.ok(bounds.y >= toolbar.y + toolbar.height || bounds.y + bounds.height <= toolbar.y);
        assert.equal(await page.getByRole('toolbar').evaluate(node => getComputedStyle(node).opacity), '1');
        assert.equal(await menu.getByRole('menuitemradio').first().evaluate(node => {
          const r = node.getBoundingClientRect();
          const target = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
          return node === target || node.contains(target);
        }), true);
        await page.keyboard.press('Escape');
        assert.equal(await menu.isVisible(), false);
        assert.equal(await page.getByRole('toolbar').isVisible(), true);
      }
      await page.getByRole('button', { name: 'Text style' }).click();
      await page.getByRole('button', { name: 'Lists', exact: true }).click();
      assert.equal(await page.getByRole('menu', { name: 'Text style' }).isVisible(), false);
      await page.getByRole('button', { name: 'Edit Markdown source' }).click();
      assert.equal(await page.getByRole('toolbar').isVisible(), false);
      await page.getByRole('button', { name: 'Switch to rich text' }).click();
      await page.getByRole('button', { name: 'Text style' }).click();
      await page.evaluate(() => window.ui.setData('Restored\n'));
      assert.equal(await page.getByRole('toolbar').isVisible(), false);
      assert.equal(await page.locator('#root .tiptap').textContent(), 'Restored');
    });
  }
});

test('Markdown shortcuts create a heading and a code block immediately after four backticks', async t => {
  const page = await setup(t, '');
  await page.locator('.tiptap').click();
  await page.keyboard.type('# test');
  assert.equal(await page.locator('#root h1').textContent(), 'test');
  await page.keyboard.press('Enter');
  await page.keyboard.type('````');
  assert.equal(await page.locator('#root pre > code').count(), 1);
  assert.equal(await page.locator('#root pre > code').textContent(), '');
  await page.keyboard.type('first line');
  await page.keyboard.press('Enter');
  await page.keyboard.type('second line');
  assert.equal(await page.locator('#root pre > code').textContent(), 'first line\nsecond line');
  await page.keyboard.press('ControlOrMeta+Enter');
  await page.keyboard.type('After code');
  assert.equal(await page.locator('#root .tiptap > p').last().textContent(), 'After code');
});

test('selection opens a formatting bubble, applies bold, and reset preserves the mounted editor', async t => {
  const page = await setup(t, 'Original text\n');
  await page.locator('.tiptap').click();
  await page.keyboard.press('ControlOrMeta+a');
  await page.getByRole('toolbar', { name: 'Text formatting' }).waitFor({ state: 'visible' });
  const palette = () => page.getByRole('button', { name: 'Bold', exact: true }).evaluate(node => {
    const style = getComputedStyle(node);
    return { background: style.backgroundColor, color: style.color, border: style.borderColor };
  });
  const inactivePalette = await palette();
  await page.getByRole('button', { name: 'Bold', exact: true }).click();
  assert.ok((await page.evaluate(() => window.ui.getData())).includes('**Original text**'));
  assert.equal(await page.getByRole('button', { name: 'Bold', exact: true }).getAttribute('aria-pressed'), 'true');
  const activePalette = await palette();
  for (const property of ['background', 'color', 'border']) assert.notEqual(activePalette[property], inactivePalette[property]);
  await page.getByRole('button', { name: 'Bold', exact: true }).hover();
  assert.deepEqual(await palette(), activePalette);
  await page.evaluate(() => { window.originalNode = document.querySelector('.tiptap'); window.ui.setData('Reset\n'); });
  assert.equal(await page.evaluate(() => window.originalNode === document.querySelector('.tiptap')), true);
  assert.equal(await page.evaluate(() => window.ui.getData()), 'Reset\n');
});

test('the link bubble rejects executable URLs, accepts corrections, and closes with Escape', async t => {
  const page = await setup(t, 'Example\n');
  await page.locator('.tiptap').click();
  await page.keyboard.press('ControlOrMeta+a');
  await page.getByRole('button', { name: 'Link', exact: true }).click();
  await page.getByRole('textbox', { name: 'Link URL' }).fill('javascript:alert(1)');
  await page.getByRole('button', { name: 'Apply link' }).click();
  assert.equal(await page.evaluate(() => window.ui.getData()), 'Example\n');
  await page.getByRole('textbox', { name: 'Link URL' }).fill('https://example.org');
  await page.getByRole('button', { name: 'Apply link' }).click();
  assert.equal(await page.locator('.tiptap a').getAttribute('href'), 'https://example.org');
  await page.getByRole('button', { name: 'Link', exact: true }).click();
  await page.getByRole('textbox', { name: 'Link URL' }).press('Escape');
  assert.equal(await page.getByRole('toolbar', { name: 'Text formatting' }).isVisible(), false);
});

test('new code blocks accept multiple lines and blank lines, indentation, language selection, undo and Reset', async t => {
  const page = await setup(t, '');
  await page.locator('.tiptap').click();
  await page.getByRole('button', { name: 'Code block', exact: true }).click();
  await page.keyboard.type('const title = "Card";');
  await page.keyboard.press('Enter');
  await page.keyboard.press('Tab');
  await page.keyboard.type('console.log(title);');
  await page.keyboard.press('Enter');
  await page.keyboard.press('Enter');
  await page.keyboard.press('Enter');
  await page.keyboard.type('// Keep blank lines');
  const code = page.locator('#root pre > code');
  assert.equal(await page.getByRole('button', { name: 'Bold', exact: true }).isDisabled(), true);
  assert.equal(await page.getByRole('button', { name: 'Code block', exact: true }).getAttribute('aria-pressed'), 'true');
  const text = 'const title = "Card";\n  console.log(title);\n\n\n// Keep blank lines';
  assert.equal(await code.textContent(), text);
  assert.equal(await page.locator('#root .tiptap > p').count(), 0);
  const language = page.locator('#root select[aria-label="Code language"]');
  await page.mouse.move(0, 0);
  assert.equal(await language.isVisible(), true, 'keyboard editing must expose the language control');
  await language.selectOption('javascript');
  assert.equal(await code.textContent(), text);
  assert.equal(await page.evaluate(() => window.ui.getData()), '```javascript\n' + text + '\n```');
  assert.ok(await code.locator('.hljs-keyword').count());
  await page.keyboard.press('ControlOrMeta+z');
  assert.equal(await code.count(), 1);
  assert.equal(await language.inputValue(), '');
  assert.equal(await code.textContent(), text);
  await page.evaluate(() => window.ui.setData('```php\n<?php\nreturn "Reset";\n```\n'));
  assert.equal(await language.inputValue(), 'php');
  assert.equal(await code.textContent(), '<?php\nreturn "Reset";');
  assert.ok(await code.locator('.hljs-keyword').count());
});

test('pasted multiline code stays literal, including tabs, trailing spaces and embedded Markdown fences', async t => {
  const page = await setup(t, '```\n\n```\n');
  await page.locator('#root pre').click({ position: { x: 16, y: 16 } });
  const text = 'function example() {\n\treturn "<script>alert(1)</script>";  \n}\n\n```\n';
  await page.locator('#root pre > code').evaluate((code, text) => {
    const clipboardData = new DataTransfer();
    clipboardData.setData('text/plain', text);
    code.dispatchEvent(new ClipboardEvent('paste', { clipboardData, bubbles: true, cancelable: true }));
  }, text);
  assert.equal(await page.locator('#root pre > code').textContent(), text);
  const saved = await page.evaluate(() => window.ui.getData());
  assert.equal(saved, '````\n' + text + '\n````');
  const rendered = await page.evaluate(async markdown => {
    const { renderMarkdown } = await import('/renderer.js');
    const div = document.createElement('div');
    div.innerHTML = renderMarkdown(markdown);
    return { text: div.querySelector('pre > code').textContent, scripts: div.querySelectorAll('script').length };
  }, saved);
  assert.deepEqual(rendered, { text: text + '\n', scripts: 0 });
  await page.evaluate(markdown => window.ui.setData(markdown), saved);
  assert.equal(await page.locator('textarea').isVisible(), false);
  assert.equal(await page.locator('#root pre > code').textContent(), text);
});

test('each block keeps its own imported language, while unknown languages remain unchanged and unhighlighted', async t => {
  const source = '```js\nconst first = 1;\n```\n\n```unlisted-language\nsecond\n```\n\nOriginal paragraph\n';
  const page = await setup(t, source);
  assert.equal(await page.locator('textarea').isVisible(), false);
  assert.equal(await page.evaluate(() => window.ui.getData()), source);
  const blocks = page.locator('#root .frontend-studio-code-block');
  assert.equal(await blocks.nth(0).locator('select').inputValue(), 'js');
  assert.equal(await blocks.nth(1).locator('select').inputValue(), 'unlisted-language');
  assert.equal(await blocks.nth(1).locator('code [class*="hljs-"]').count(), 0);
  await page.locator('#root .tiptap p').last().click({ position: { x: 12, y: 12 } });
  await page.keyboard.press('End');
  await page.keyboard.type(' edited');
  assert.ok((await page.evaluate(() => window.ui.getData())).includes('```unlisted-language\nsecond\n```'));
  await blocks.nth(0).hover();
  await blocks.nth(0).locator('select').selectOption('typescript');
  assert.equal(await blocks.nth(1).locator('select').inputValue(), 'unlisted-language');
  assert.ok((await page.evaluate(() => window.ui.getData())).startsWith('```typescript\nconst first = 1;\n```'));
  await blocks.nth(0).hover();
  await blocks.nth(0).locator('select').selectOption('');
  assert.equal(await blocks.nth(0).locator('code [class*="hljs-"]').count(), 0);
  assert.ok((await page.evaluate(() => window.ui.getData())).startsWith('```\nconst first = 1;\n```'));
});

test('editor and rendered Docs use the same highlighting and geometry in light and dark themes without focus flicker', async t => {
  for (const dark of [false, true]) {
    await t.test(dark ? 'dark' : 'light', async t => {
      const page = await setup(t, '```javascript\nconst title = "Card";\nconsole.log(title);\n```\n', { dark });
      await page.mouse.move(0, 0);
      assert.equal(await page.getByRole('combobox', { name: 'Code language' }).isVisible(), false);
      const before = await page.locator('#root pre').boundingBox();
      assert.equal(before.height, (await page.locator('#reference pre').boundingBox()).height);
      await page.evaluate(() => { window.codeNode = document.querySelector('#root pre > code'); });
      const styles = await page.evaluate(() => {
        const properties = ['fontSize', 'lineHeight', 'paddingTop', 'paddingBottom', 'color', 'backgroundColor'];
        const get = parent => properties.map(property => getComputedStyle(document.querySelector(parent + ' pre'))[property]);
        const color = parent => getComputedStyle(document.querySelector(parent + ' .hljs-keyword')).color;
        return { editor: get('#root'), preview: get('#reference'), editorColor: color('#root'), previewColor: color('#reference') };
      });
      assert.deepEqual(styles.editor, styles.preview);
      assert.equal(styles.editorColor, styles.previewColor);
      assert.equal(styles.editorColor, dark ? 'rgb(255, 123, 114)' : 'rgb(207, 34, 46)');
      await page.locator('#root pre').hover();
      assert.deepEqual(await page.locator('#root pre').boundingBox(), before);
      await page.locator('#root pre > code').click();
      assert.deepEqual(await page.locator('#root pre').boundingBox(), before);
      assert.equal(await page.evaluate(() => window.codeNode === document.querySelector('#root pre > code')), true);
      assert.equal(await page.evaluate(() => window.changes.length), 0);
    });
  }
});

test('Fluid code in the editor and rendered Docs uses PHP tokens and colors in light and dark mode', async t => {
  const fixture = fluidFixtures.find(value => value.name === 'complete example');
  const markdown = '```fluid\n' + fixture.source + '\n```\n';
  for (const dark of [false, true]) {
    await t.test(dark ? 'dark' : 'light', async t => {
      const page = await setup(t, markdown, { dark });
      assert.equal(await page.evaluate(() => window.ui.getData()), markdown);
      assert.equal(await page.evaluate(() => window.changes.length), 0);
      const code = page.locator('#root pre > code');
      assert.equal(await code.textContent(), fixture.source);
      assert.equal(await page.locator('#root select[aria-label="Code language"]').inputValue(), 'fluid');
      assert.equal(await page.locator('#root .frontend-studio-code-language-label').textContent(), 'Fluid');
      const colors = await page.evaluate(tokens => {
        // TYPO3 supplies this variable; define it for the standalone browser fixture too.
        document.documentElement.style.setProperty('--typo3-text-color-variant', '#8b949e');
        const prefix = 'frontend-studio-variant-html-source__';
        const php = document.createElement('pre');
        php.id = 'php-reference'; php.className = 'frontend-studio-variant-html-source';
        for (const [token, value] of tokens) {
          const node = token === null ? document.createTextNode(value) : document.createElement('span');
          if (token !== null) { node.className = prefix + token; node.textContent = value; }
          php.append(node);
        }
        document.body.append(php);
        const read = (selector) => {
          const rows = [];
          const walker = document.createTreeWalker(document.querySelector(selector), NodeFilter.SHOW_TEXT);
          while (walker.nextNode()) {
            const parent = walker.currentNode.parentElement;
            const kind = Array.from(parent.classList).find(name => name.startsWith(prefix))?.slice(prefix.length) || null;
            const color = kind === null ? null : getComputedStyle(parent).color;
            const value = walker.currentNode.textContent;
            const previous = rows.at(-1);
            if (previous && previous[0] === kind && previous[2] === color) previous[1] += value;
            else rows.push([kind, value, color]);
          }
          // The Markdown renderer adds a final newline; it has no visible token color.
          return rows.filter(row => row[0] !== null);
        };
        return { php: read('#php-reference'), editor: read('#root pre > code'), docs: read('#reference pre > code') };
      }, fixture.tokens);
      assert.deepEqual(colors.editor, colors.php);
      assert.deepEqual(colors.docs, colors.php);
      await code.click();
      const select = page.locator('#root select[aria-label="Code language"]');
      await select.selectOption('html');
      assert.equal(await code.locator('[class*="frontend-studio-variant-html-source__"]').count(), 0);
      assert.ok(await code.locator('.hljs-name').count());
      await select.selectOption('fluid');
      assert.equal(await code.textContent(), fixture.source);
      assert.ok(await code.locator('.frontend-studio-variant-html-source__fluid-expression').count());
      // Set the model selection and focus together; DOM caret changes can lag behind typing.
      await page.locator('#root .tiptap').evaluate(node => {
        const editor = node.editor;
        editor.commands.setTextSelection(editor.state.doc.content.size - 1);
        editor.view.focus();
      });
      await page.keyboard.type('\n{newVariable}');
      await code.locator('.frontend-studio-variant-html-source__fluid-expression', { hasText: 'newVariable' }).waitFor();
      assert.ok((await page.evaluate(() => window.ui.getData())).startsWith('```fluid\n'));
      assert.ok((await code.textContent()).endsWith('{newVariable}'));
      assert.equal(await page.locator('#root script, #reference script').count(), 0);
    });
  }
});

test('images, quotes, inline and fenced code, horizontal rules and tables survive unrelated rich edits', async t => {
  for (const feature of ['![Screenshot](foo.png)', '> Quote', 'Use `inline code`.', '---', '```\ncode\n```', '```html\n<site:card />\n```', '| A | B |\n| --- | --- |\n| 1 | 2 |']) {
    await t.test(feature, async t => {
      const source = `${feature}\n\nOriginal paragraph\n`;
      const page = await setup(t, source);
      assert.equal(await page.locator('textarea').isVisible(), false, 'supported content should open as rich text');
      assert.equal(await page.evaluate(() => window.ui.getData()), source);
      assert.equal(await page.evaluate(() => window.changes.length), 0);
      // Set the editor's selection before typing; DOM caret updates can lag behind.
      await page.locator('#root .tiptap').evaluate(node => {
        const editor = node.editor;
        editor.commands.setTextSelection(editor.state.doc.content.size - 1);
        editor.view.focus();
      });
      await page.keyboard.type(' edited');
      const saved = await page.evaluate(() => window.ui.getData());
      assert.ok(saved.includes('Original paragraph edited'), saved);
      if (feature.startsWith('|')) { assert.ok( /\| A\s+\| B\s+\|/.test(saved), saved); assert.ok( /\| 1\s+\| 2\s+\|/.test(saved), saved); }
      else assert.ok(saved.includes(feature), saved);
    });
  }
});

test('unsupported Markdown stays verbatim in source mode across edits, attempted switches, reset and reload', async t => {
  const source = '![Embedded](data:image/png;base64,iVBORw0KGgo=)\n\nOriginal\n';
  const page = await setup(t, source);
  const textarea = page.getByRole('textbox', { name: 'Component documentation Markdown' });
  await textarea.waitFor({ state: 'visible' });
  assert.equal(await textarea.inputValue(), source);
  const edited = source.replace('Original', 'Edited');
  await textarea.fill(edited);
  await page.getByRole('button', { name: 'Switch to rich text' }).click();
  assert.equal(await textarea.isVisible(), true);
  assert.equal(await page.evaluate(() => window.ui.getData()), edited);
  await page.evaluate(source => window.ui.setData(source), source);
  assert.equal(await textarea.inputValue(), source);
  await textarea.fill('# Supported again\n');
  await page.getByRole('button', { name: 'Switch to rich text' }).click();
  assert.equal(await textarea.isVisible(), false);
  assert.equal(await page.evaluate(() => window.ui.getData()), '# Supported again\n');
});

test('Markdown source remains exact until a rich edit and source toggles do not mark a document dirty', async t => {
  const page = await setup(t, '# Heading\n\n*custom*\n');
  await page.locator('.tiptap').click();
  await page.getByRole('button', { name: 'Edit Markdown source' }).click();
  const textarea = page.getByRole('textbox', { name: 'Component documentation Markdown' });
  assert.equal(await textarea.inputValue(), '# Heading\n\n*custom*\n');
  await page.getByRole('button', { name: 'Switch to rich text' }).click();
  assert.equal(await page.evaluate(() => window.changes.length), 0);
  await page.getByRole('button', { name: 'Edit Markdown source' }).click();
  await textarea.fill('# Source\n\n**Exact text**\n');
  assert.equal(await page.evaluate(() => window.ui.getData()), '# Source\n\n**Exact text**\n');
  const changes = await page.evaluate(() => window.changes.length);
  await page.evaluate(() => window.ui.setData('Reset from disk\n'));
  assert.equal(await page.evaluate(() => window.changes.length), changes);
  assert.equal(await page.evaluate(() => window.ui.getData()), 'Reset from disk\n');
});

test('empty documents are editable, dark mode uses theme colors, and destroying removes floating UI', async t => {
  const page = await setup(t, '');
  await page.locator('.tiptap').click();
  await page.keyboard.type('New documentation');
  assert.equal(await page.evaluate(() => window.ui.getData()), 'New documentation');
  await page.evaluate(() => document.documentElement.style.cssText = '--typo3-text-color-base:#eee;--typo3-surface-container-lowest:#222;--typo3-surface-container-low:#333;--typo3-component-border-color:#444;');
  assert.equal(await page.locator('.tiptap').evaluate(node => getComputedStyle(node).color), 'rgb(238, 238, 238)');
  await page.evaluate(() => window.ui.destroy());
  assert.equal(await page.locator('.frontend-studio-editor-bubble').count(), 0);
});
