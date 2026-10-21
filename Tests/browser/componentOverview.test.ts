import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createContext, SourceTextModule, SyntheticModule } from 'node:vm';
import test from 'node:test';

import { DOMParser } from 'linkedom';

async function setup(height = 600, documentation = true) {
  const document = new DOMParser().parseFromString(`<html><body><main>${documentation ? `
    <div data-overview-description><div data-overview-description-content><pre data-overview-markdown>Documentation</pre></div></div>
    <button data-overview-description-toggle hidden aria-expanded="false">Expand documentation</button>` : ''}</main></body></html>`, 'text/html');
  const root = document.querySelector('main');
  const description = root.querySelector('[data-overview-description]');
  const content = root.querySelector('[data-overview-description-content]');
  const button = root.querySelector('button');
  if (content) content.getBoundingClientRect = () => ({ height });
  const observers: any[] = [];
  const context = createContext({
    AbortController, Event, EventTarget,
    document: { querySelectorAll: () => [] },
    getComputedStyle: () => ({ getPropertyValue: () => '320px' }),
    ResizeObserver: class {
      observed = false;
      callback: () => void;
      constructor(callback: () => void) { this.callback = callback; observers.push(this); }
      observe() { this.observed = true; }
      disconnect() { this.observed = false; }
    },
  });
  const lifecycle = new SourceTextModule(await readFile(new URL('../../Resources/Public/JavaScript/Backend/variant-lifecycle.js', import.meta.url), 'utf8'), { context });
  await lifecycle.link(() => { throw new Error('Unexpected dependency'); });
  await lifecycle.evaluate();
  const dependencies: Record<string, Record<string, unknown>> = {
    '@typo3/core/document-service.js': { default: { ready: () => Promise.resolve() } },
    '@andersundsehr/frontend-studio/backend/variant-preview.js': { default: class {} },
    '@andersundsehr/frontend-studio/backend/variant-state.js': { getVariantState() { throw new Error('Unexpected mount'); } },
    '@andersundsehr/frontend-studio/backend/markdown-renderer.js': { renderMarkdown: (source: string) => `<p>${source}</p>` },
  };
  const module = new SourceTextModule(await readFile(new URL('../../Resources/Public/JavaScript/Backend/component-overview.js', import.meta.url), 'utf8'), { context });
  await module.link(async (specifier) => {
    if (specifier.endsWith('/variant-lifecycle.js')) return lifecycle;
    const values = dependencies[specifier];
    assert.ok(values, specifier);
    const dependency = new SyntheticModule(Object.keys(values), function () {
      for (const [name, value] of Object.entries(values)) this.setExport(name, value);
    }, { context });
    await dependency.link(() => { throw new Error('Unexpected dependency'); });
    return dependency;
  });
  await module.evaluate();
  const Overview = (module.namespace as any).default;
  const view = new EventTarget();
  const overview = new Overview(root, view);
  return {
    overview, view, description, content, button, observer: observers[0],
    click: () => button.dispatchEvent(new document.defaultView.Event('click')),
    focus: () => description.dispatchEvent(new document.defaultView.Event('focusin')),
    resize: (next: number) => { height = next; observers[0].callback(); },
  };
}

test('long documentation starts faded and collapsed, and its button expands and collapses it', async () => {
  const ui = await setup();
  assert.equal(ui.description.classList.contains('is-collapsed'), true);
  assert.equal(ui.button.hidden, false);
  assert.equal(ui.button.getAttribute('aria-expanded'), 'false');
  assert.equal(ui.content.innerHTML, '<p>Documentation</p>');
  ui.click();
  assert.equal(ui.description.classList.contains('is-collapsed'), false);
  assert.equal(ui.button.getAttribute('aria-expanded'), 'true');
  assert.equal(ui.button.textContent, 'Collapse documentation');
  ui.click();
  assert.equal(ui.description.classList.contains('is-collapsed'), true);
  assert.equal(ui.button.textContent, 'Expand documentation');
  ui.overview.destroy();
});

test('short documentation has no toggle and resizing or delayed content can change whether it is long', async () => {
  const ui = await setup(320);
  assert.equal(ui.description.classList.contains('is-collapsed'), false);
  assert.equal(ui.button.hidden, true);
  ui.resize(321);
  assert.equal(ui.description.classList.contains('is-collapsed'), true);
  assert.equal(ui.button.hidden, false);
  ui.click();
  ui.resize(800);
  assert.equal(ui.button.getAttribute('aria-expanded'), 'true');
  ui.resize(100);
  assert.equal(ui.button.hidden, true);
  assert.equal(ui.button.getAttribute('aria-expanded'), 'false');
  ui.resize(600);
  assert.equal(ui.description.classList.contains('is-collapsed'), true);
  ui.overview.destroy();
});

test('keyboard focus reveals documentation links and observers suspend, resume and disconnect on destruction', async () => {
  const ui = await setup();
  ui.focus();
  assert.equal(ui.description.classList.contains('is-collapsed'), false);
  assert.equal(ui.button.getAttribute('aria-expanded'), 'true');
  ui.view.dispatchEvent(new Event('suspend'));
  assert.equal(ui.observer.observed, false);
  ui.view.dispatchEvent(new Event('resume'));
  assert.equal(ui.observer.observed, true);
  assert.equal(ui.button.getAttribute('aria-expanded'), 'true');
  ui.overview.destroy();
  assert.equal(ui.observer.observed, false);
  ui.resize(100);
  assert.equal(ui.button.getAttribute('aria-expanded'), 'true');
});

test('an overview without documentation does not create a description observer', async () => {
  const ui = await setup(0, false);
  assert.equal(ui.observer, undefined);
  ui.overview.destroy();
});
