import assert from 'node:assert/strict';
import { test } from 'node:test';
import { backendModules } from './backendModules.ts';

test('template diagnostics refresh without preview, preserve controls, clear errors and reject stale responses', async () => {
  const elements = new Map(['source', 'status', 'badge', 'count'].map(name => [name, { innerHTML: '', textContent: '', hidden: true }]));
  const root = { querySelector: (selector: string) => elements.get(selector.match(/template-(\w+)/)![1]) };
  const view = Object.assign(new EventTarget(), { variantIdentifier: 'site:card:Default', hasUnsavedChanges: true, controls: { value: 'Unsaved' } });
  const requests: { url: URL; signal: AbortSignal; resolve: (response: unknown) => void }[] = [];
  const modules = backendModules({
    window: { location: { href: 'https://example.test/typo3/module' } },
    TYPO3: { settings: { ajaxUrls: { frontend_studio_template_analysis: '/typo3/ajax/template?token=protected' } } },
    fetch: (url: URL, options: { signal: AbortSignal }) => new Promise(resolve => requests.push({ url, signal: options.signal, resolve })),
  });
  const { default: Template } = await modules.import('variant-template.js');
  const panel = new Template(root, view);
  const first = panel.refresh();
  const second = panel.refresh();
  assert.equal(requests[0].signal.aborted, true);
  assert.equal(requests[1].url.searchParams.get('componentVariant'), view.variantIdentifier);
  assert.equal(requests[1].url.searchParams.get('token'), 'protected');
  requests[1].resolve({ ok: true, json: async () => ({ source: '<pre>current</pre>', errorCount: 2, status: '' }) });
  await second;
  requests[0].resolve({ ok: true, json: async () => ({ source: 'stale', errorCount: 0, status: '' }) });
  await first;
  assert.equal(elements.get('source')!.innerHTML, '<pre>current</pre>');
  assert.equal(elements.get('badge')!.hidden, false);
  assert.equal(elements.get('count')!.textContent, '2');
  const fixed = panel.refresh();
  requests[2].resolve({ ok: true, json: async () => ({ source: '<pre>fixed</pre>', errorCount: 0, status: '' }) });
  await fixed;
  assert.equal(elements.get('badge')!.hidden, true);
  assert.equal(view.controls.value, 'Unsaved');
  assert.equal(view.hasUnsavedChanges, true);
  const failed = panel.refresh();
  requests[3].resolve({ ok: false, status: 403 });
  await failed;
  assert.match(elements.get('status')!.textContent, /403/);
  assert.equal(elements.get('badge')!.hidden, false);
  const navigation = panel.refresh();
  panel.destroy();
  requests[4].resolve({ ok: true, json: async () => ({ source: 'stale navigation', errorCount: 0, status: '' }) });
  await navigation;
  assert.equal(elements.get('source')!.innerHTML, '<pre>fixed</pre>');
});
