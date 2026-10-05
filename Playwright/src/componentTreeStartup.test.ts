import assert from 'node:assert/strict';
import { test } from 'node:test';
import { backendModules } from './backendModules.ts';

test('startup hands the development URI to the persistent tree and restores it from the cache', async () => {
  const root = { dataset: { componentChangeStreamUri: '/changes' } };
  const document = { querySelector: () => root, title: 'Variant' };
  const topDocument = Object.assign(new EventTarget(), { querySelectorAll: () => [], querySelector: () => ({}) });
  const events: any[] = [];
  topDocument.addEventListener('typo3-module-loaded', (event) => events.push((event as CustomEvent).detail));
  const top = { document: topDocument, CustomEvent, TYPO3: {
    ModuleMenu: { App: { getCurrentModule: () => 'admin_frontendstudio' } },
    Backend: { NavigationContainer: {} },
  } };
  const window = Object.assign(new EventTarget(), { top, location: { href: 'https://example.test/variant' }, clearTimeout });
  const modules = backendModules({ document, window });
  await modules.import('component-tree-startup.js');
  assert.equal(events.length, 1);
  assert.equal(events[0].componentChangeStreamUri, '/changes');
  window.dispatchEvent(Object.assign(new Event('pagehide'), { persisted: true }));
  window.dispatchEvent(Object.assign(new Event('pageshow'), { persisted: true }));
  assert.equal(events.length, 2);
  assert.equal(events[1].componentChangeStreamUri, '/changes');
});

test('leaving a variant cancels pending navigation startup retries', async () => {
  let retry: (() => void) | null = null;
  const window = Object.assign(new EventTarget(), {
    top: null,
    setTimeout: (callback: () => void) => { retry = callback; return 1; },
    clearTimeout: (id: number) => { assert.equal(id, 1); retry = null; },
  });
  const modules = backendModules({ document: { querySelector: () => ({}) }, window });
  await modules.import('component-tree-startup.js');
  assert.notEqual(retry, null);
  window.dispatchEvent(new Event('pagehide'));
  assert.equal(retry, null);
});
