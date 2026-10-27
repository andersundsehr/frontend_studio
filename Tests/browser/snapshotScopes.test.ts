import assert from 'node:assert/strict';
import test from 'node:test';
import { backendModules } from '../../Playwright/src/backendModules.ts';

test('scope membership avoids repeated catalog scans when progress and results change', async () => {
  const { default: SnapshotTests } = await backendModules().import('snapshot-tests.js');
  const tests = new SnapshotTests(() => {});
  const catalog = Array.from({ length: 250 }, (_, index) => `site:folder.card${index}:Default`);
  let reads = 0;
  tests.catalog = new Proxy(catalog, {
    get(target, key, receiver) {
      if (/^\d+$/.test(String(key))) reads++;
      return Reflect.get(target, key, receiver);
    },
  });
  const render = () => {
    for (const identifier of catalog) {
      tests.progress(identifier, 'variant');
      tests.status(identifier, 'variant');
      tests.progress(identifier.split(':').slice(0, 2).join(':'), 'component');
      tests.status(identifier.split(':').slice(0, 2).join(':'), 'component');
    }
    tests.progress('site:folder.', 'folder');
    tests.status('site:', 'namespace');
  };
  render();
  assert.ok(reads > 0);
  for (const status of ['queued', 'running', 'passed', 'failed']) {
    tests.pending.clear();
    tests.results.clear();
    if (status === 'queued' || status === 'running') tests.pending.set(catalog[0], status);
    else tests.results.set(catalog[0], { identifier: catalog[0], status });
    reads = 0;
    render();
    assert.equal(reads, 0, `${status}: updating tree indicators must reuse catalog scope membership.`);
    assert.equal(tests.progress(catalog[0], 'variant'), status === 'queued' || status === 'running' ? status : null);
    assert.equal(tests.status(catalog[0], 'variant'), status === 'passed' || status === 'failed' ? status : 'not-run');
  }
});

test('scope membership distinguishes scope types and refreshes when the catalog changes', async () => {
  const { default: SnapshotTests } = await backendModules().import('snapshot-tests.js');
  const tests = new SnapshotTests(() => {});
  const identifier = 'site:card:Mobile';
  tests.catalog = [identifier, identifier + ':Dark'];
  assert.deepEqual([...tests.identifiers(identifier, 'variant')], [identifier]);
  assert.deepEqual([...tests.identifiers(identifier)], [identifier, identifier + ':Dark']);
  tests.catalog = [identifier, 'site:other:Default'];
  assert.deepEqual([...tests.identifiers(identifier)], [identifier]);
  tests.catalog.push(identifier + ':Light');
  assert.deepEqual([...tests.identifiers(identifier)], [identifier, identifier + ':Light']);
  tests.invalidate();
  assert.deepEqual([...tests.identifiers(identifier)], []);
});
