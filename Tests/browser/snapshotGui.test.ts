import assert from 'node:assert/strict';
import { test } from 'node:test';
import { backendModules } from '../../Playwright/src/backendModules.ts';

const a = 'site:folder.card:Default';
const b = 'site:folder.card:Second';
const c = 'site:other:Default';
async function state(request: (body: any, signal: AbortSignal) => Promise<any>, settings: Record<string, unknown> = {}) {
  const modules = backendModules({ TYPO3: { settings: { frontendStudio: settings } } });
  const { default: Tests } = await modules.import('snapshot-tests.js');
  const tests = new Tests(() => {}, request);
  tests.setContext({ site: 'preview', language: 'de' });
  return { tests, modules };
}

function result(identifier: string, status = 'passed') {
  return { identifier, status, message: status, expected: '<p>old</p>', actual: '<p>new</p>' };
}

test('partial runs use the complete catalog for parent aggregation and total counts', async () => {
  const { tests, modules } = await state(async (body) => body.operation === 'discover'
    ? { identifiers: [body.scope], catalog: [a, b, c] }
    : { result: result(body.scope, body.scope === b ? 'missing' : 'passed') });
  await tests.run(a);
  assert.equal(tests.status(a, 'variant'), 'passed');
  assert.equal(tests.status('site:folder.card', 'component'), 'not-run');
  assert.equal(tests.passed, 1);
  assert.equal(tests.catalog.length, 3);
  await tests.run(b);
  assert.equal(tests.status('site:folder.', 'folder'), 'failed');
  assert.equal(tests.status('site:', 'namespace'), 'failed');
  assert.equal(tests.failures('site:folder.', 'folder')[0].identifier, b);
  assert.equal(tests.status('site:other', 'component'), 'not-run');
  assert.ok(![...modules.loaded].some((name) => name.endsWith('snapshot-diff.js')));
});

test('test all runs every server identifier even when no rows are visible', async () => {
  const calls: string[] = [];
  const { tests } = await state(async (body) => {
    if (body.operation === 'discover') return { identifiers: [a, b, c], catalog: [a, b, c] };
    calls.push(body.scope);
    return { result: result(body.scope) };
  });
  await tests.run();
  assert.deepEqual(calls, [a, b, c]);
  assert.equal(tests.status(), 'passed');
  assert.equal(tests.completed, 3);
  assert.equal(tests.passed, 3);
});

for (const invalidate of ['files', 'context']) {
  for (const [change, nextCatalog] of [
    ['deleted variant', [a, c]],
    ['added variant', [a, b, c, 'site:other:Added']],
  ] as const) {
    test(`${invalidate} invalidation clears the catalog before rediscovering a ${change}`, async () => {
      let catalog: readonly string[] = [a, b, c];
      let releaseDiscovery: () => void = () => {};
      let discoveryGate: Promise<void> | undefined;
      const { tests } = await state(async (body) => {
        if (body.operation === 'discover') {
          await discoveryGate;
          return { catalog: [...catalog], identifiers: catalog.filter((id) => !body.scope || id === body.scope) };
        }
        return { result: result(body.scope) };
      });
      await tests.run();
      assert.equal(tests.passed, 3);
      tests.errors.set('site:old', 'Previous discovery error');
      const counts: string[] = [];
      tests.changed = () => counts.push(`${tests.passed}/${tests.catalog.length} passed`);
      if (invalidate === 'context') tests.setContext({ site: 'preview', language: 'en' });
      else tests.invalidate();
      assert.deepEqual([...tests.catalog], []);
      assert.equal(tests.results.size, 0);
      assert.equal(tests.errors.size, 0);
      assert.equal(tests.completed, 0);
      assert.equal(tests.total, 0);
      assert.equal(tests.active, false);
      assert.equal(counts[0], '0/0 passed', 'The first UI notification must already exclude the stale catalog.');
      catalog = nextCatalog;
      discoveryGate = new Promise((resolve) => { releaseDiscovery = resolve; });
      const run = tests.run(a);
      assert.equal(counts.at(-1), '0/0 passed', 'Totals must stay empty until discovery completes.');
      releaseDiscovery();
      await run;
      assert.deepEqual([...tests.catalog], [...nextCatalog], 'Partial rediscovery must restore the complete changed catalog.');
      assert.deepEqual([...tests.results.keys()], [a]);
      assert.equal(counts.at(-1), `1/${nextCatalog.length} passed`);
      await tests.run();
      assert.deepEqual([...tests.results.keys()], [...nextCatalog]);
      assert.equal(counts.at(-1), `${nextCatalog.length}/${nextCatalog.length} passed`);
    });
  }
}

const similarComponent = 'site:folder.cardExtra:Default';
const colonVariant = a + ':Extra';
const otherNamespace = 'other:card:Default';
const similarFolder = 'site:folderExtra.card:Default';
const previousCatalog = [a, b, c, similarComponent, colonVariant, otherNamespace, similarFolder];

for (const [name, scope, cleared] of [
  ['all', '', previousCatalog],
  ['namespace', 'site:', [a, b, c, similarComponent, colonVariant, similarFolder]],
  ['folder', 'site:folder.', [a, b, similarComponent, colonVariant]],
  ['component', 'site:folder.card', [a, b, colonVariant]],
  ['variant', a, [a]],
  ['colon-containing variant', colonVariant, [colonVariant]],
] as const) {
  test(`a new ${name} run clears scoped passes before discovery and keeps them cleared when discovery fails`, async () => {
    let failDiscovery = false;
    let rejectDiscovery: (error: Error) => void = () => {};
    const { tests } = await state(async (body) => {
      if (body.operation === 'discover') {
        if (failDiscovery) return new Promise((_resolve, reject) => { rejectDiscovery = reject; });
        return { identifiers: previousCatalog, catalog: previousCatalog };
      }
      return { result: result(body.scope) };
    });
    await tests.run();
    assert.equal(tests.passed, previousCatalog.length);
    assert.equal(tests.status(), 'passed');
    const previous = new Map(tests.results);
    const remaining = previousCatalog.filter((id) => !(cleared as readonly string[]).includes(id));
    const notifications: string[][] = [];
    tests.changed = () => notifications.push([...tests.results.keys()]);
    failDiscovery = true;

    const run = tests.run(scope);
    assert.deepEqual([...tests.results.keys()], remaining);
    assert.deepEqual(notifications[0], remaining, 'The first UI update must already exclude stale results.');
    assert.equal(tests.active, true);
    assert.equal(tests.completed, 0);
    assert.equal(tests.total, 0);
    assert.equal(tests.passed, remaining.length);
    for (const id of cleared) assert.equal(tests.status(id, 'variant'), 'not-run');

    rejectDiscovery(new Error('Discovery unavailable'));
    await run;
    assert.equal(tests.active, false);
    assert.equal(tests.passed, remaining.length);
    assert.deepEqual([...tests.results.keys()], remaining);
    assert.equal(tests.status(), 'failed');
    assert.equal(tests.failures()[0].message, 'Discovery unavailable');
    for (const id of remaining) {
      assert.equal(tests.results.get(id), previous.get(id), 'Unrelated results must be retained unchanged.');
      assert.equal(tests.status(id, 'variant'), 'passed');
    }
  });
}

for (const staleFailure of [false, true]) {
  test(`cancelled discovery ${staleFailure ? 'errors' : 'responses'} cannot overwrite a newer successful run`, async () => {
    let settleDiscovery: () => void = () => {};
    let oldSignal: AbortSignal | undefined;
    let discoveries = 0;
    const { tests } = await state(async (body, signal) => {
      if (body.operation === 'discover') {
        if (discoveries++ === 0) {
          oldSignal = signal;
          return new Promise((resolve, reject) => {
            settleDiscovery = () => staleFailure ? reject(new Error('Old discovery failed')) : resolve({ identifiers: [a], catalog: [a] });
          });
        }
        return { identifiers: [b], catalog: [b, c] };
      }
      return { result: result(body.scope) };
    });
    const cancelled = tests.run(a);
    tests.invalidate();
    assert.equal(oldSignal?.aborted, true);
    assert.equal(tests.catalog.length, 0);
    await tests.run(b);
    settleDiscovery();
    await cancelled;
    assert.deepEqual([...tests.catalog], [b, c]);
    assert.deepEqual([...tests.results.keys()], [b]);
    assert.equal(tests.status(b, 'variant'), 'passed');
    assert.equal(tests.passed, 1);
    assert.equal(tests.errors.size, 0);
    assert.equal(tests.active, false);
  });
}

test('duplicate clicks are ignored and context changes discard in-flight results', async () => {
  let release: (value: any) => void = () => {};
  let signal: AbortSignal | undefined;
  let calls = 0;
  const { tests } = await state(async (_body, requestSignal) => {
    calls++;
    signal = requestSignal;
    return new Promise((resolve) => { release = resolve; });
  });
  const first = tests.run();
  await tests.run();
  assert.equal(calls, 1);
  tests.setContext({ site: 'preview', language: 'en' });
  assert.equal(signal?.aborted, true);
  release({ identifiers: [a], catalog: [a] });
  await first;
  assert.equal(tests.active, false);
  assert.equal(tests.results.size, 0);
  assert.equal(tests.catalog.length, 0);
});

test('file invalidation ignores stale variant results and clears old passes', async () => {
  let release: (value: any) => void = () => {};
  let started: () => void = () => {};
  let signal: AbortSignal | undefined;
  const pending = new Promise<void>((resolve) => { started = resolve; });
  const { tests } = await state(async (body, requestSignal) => {
    if (body.operation === 'discover') return { identifiers: [a], catalog: [a] };
    signal = requestSignal;
    started();
    return new Promise((resolve) => { release = resolve; });
  });
  const run = tests.run();
  await pending;
  assert.deepEqual([...tests.catalog], [a]);
  tests.invalidate();
  assert.equal(signal?.aborted, true);
  assert.equal(tests.catalog.length, 0);
  release({ result: result(a) });
  await run;
  assert.equal(tests.results.size, 0);
  assert.equal(tests.catalog.length, 0);
  assert.equal(tests.status(), 'not-run');
});

test('network failures remain visible and do not inflate pass counts', async () => {
  const { tests } = await state(async (body) => {
    if (body.operation === 'discover') return { identifiers: [a, b], catalog: [a, b] };
    if (body.scope === a) throw new Error('Network unavailable');
    return { result: result(b) };
  });
  await tests.run();
  assert.equal(tests.completed, 2);
  assert.equal(tests.passed, 1);
  assert.equal(tests.status(), 'failed');
  assert.equal(tests.failures()[0].message, 'Network unavailable');
});

test('discovery errors propagate to parent scope and remain inspectable', async () => {
  const { tests } = await state(async () => { throw new Error('Invalid fixture'); });
  await tests.run('site:folder.card');
  assert.equal(tests.status('site:folder.', 'folder'), 'failed');
  assert.equal(tests.status(), 'failed');
  assert.equal(tests.failures()[0].message, 'Invalid fixture');
  assert.equal(tests.passed, 0);
});

test('testing another component preserves an earlier discovery error and unrelated results', async () => {
  let failA = true;
  const { tests } = await state(async (body) => {
    if (body.operation === 'discover') {
      if (body.scope === 'site:folder.card' && failA) throw new Error('Component A discovery failed');
      return { catalog: [a, b, c, otherNamespace], identifiers: body.scope === 'site:other' ? [c] : [a, b] };
    }
    return { result: result(body.scope) };
  });
  const unrelated = result(otherNamespace);
  tests.results.set(otherNamespace, unrelated);
  await tests.run('site:folder.card');
  await tests.run('site:other');
  assert.equal(tests.errors.get('site:folder.card'), 'Component A discovery failed');
  assert.equal(tests.status('site:folder.card', 'component'), 'failed');
  assert.equal(tests.status('site:other', 'component'), 'passed');
  assert.equal(tests.results.get(otherNamespace), unrelated);
  assert.equal(tests.failures()[0].message, 'Component A discovery failed');
  failA = false;
  await tests.run('site:folder.card');
  assert.equal(tests.errors.size, 0);
  tests.errors.set('site:folder.card', 'Old A error');
  tests.errors.set('site:other', 'Old B error');
  const run = tests.run();
  assert.equal(tests.errors.size, 0, 'Test all must invalidate every discovery error immediately.');
  await run;
});

for (const [name, scope, removed] of [
  ['namespace', 'site:', previousCatalog.filter((id) => id.startsWith('site:'))],
  ['folder', 'site:folder.', [a, b, similarComponent, colonVariant]],
  ['component', 'site:folder.card', [a, b, colonVariant]],
  ['variant', a, [a]],
] as const) {
  test(`a partial ${name} run retains discovery errors outside its exact scope`, async () => {
    const { tests } = await state(async () => { throw new Error('New discovery failed'); });
    for (const id of previousCatalog) tests.errors.set(id, 'Previous ' + id);
    const run = tests.run(scope);
    for (const id of previousCatalog) assert.equal(tests.errors.has(id), !(removed as readonly string[]).includes(id));
    await run;
    for (const id of previousCatalog.filter((id) => !(removed as readonly string[]).includes(id))) {
      assert.equal(tests.errors.get(id), 'Previous ' + id);
    }
    assert.equal(tests.errors.get(scope), 'New discovery failed');
  });
}

test('GUI testing defaults on, while disabling it prevents test and update requests', async () => {
  let calls = 0;
  const request = async () => { calls++; return { identifiers: [], catalog: [] }; };
  const enabled = await state(request);
  assert.equal(enabled.tests.enabled, true);
  await enabled.tests.run();
  assert.equal(calls, 1);
  const disabled = await state(request, { snapshotTestingEnabled: false });
  await disabled.tests.run();
  await disabled.tests.showFailures();
  await assert.rejects(disabled.tests.update(a), /unavailable/);
  assert.equal(calls, 1);
  assert.equal(disabled.tests.active, false);
  assert.ok(![...disabled.modules.loaded].some((name) => name.endsWith('snapshot-diff.js')));
});

test('updating one snapshot retains other results and sends its original site and language', async () => {
  const calls: any[] = [];
  const { tests } = await state(async (body) => {
    calls.push(body);
    if (body.operation === 'discover') return { identifiers: [a, b], catalog: [a, b] };
    return { result: result(body.scope, body.operation === 'update' ? 'updated' : body.scope === a ? 'failed' : 'passed') };
  });
  await tests.run();
  const unrelated = tests.results.get(b);
  const updated = await tests.update(a);
  assert.equal(updated.status, 'updated');
  assert.equal(tests.results.get(a), updated);
  assert.equal(tests.results.get(b), unrelated);
  assert.equal(tests.passed, 1, 'An updated baseline must not become a green test result.');
  assert.equal(tests.active, false);
  assert.deepEqual(JSON.parse(JSON.stringify(calls.at(-1))), { site: 'preview', language: 'de', scope: a, operation: 'update' });
});

test('missing snapshots are created only by an explicit update and require a new passing run', async () => {
  const calls: any[] = [];
  let created = false;
  const { tests } = await state(async (body) => {
    calls.push(body);
    if (body.operation === 'discover') return { identifiers: body.scope ? [body.scope] : [a, b], catalog: [a, b] };
    if (body.operation === 'update') {
      created = true;
      return { result: result(body.scope, 'created') };
    }
    return { result: result(body.scope, body.scope === a && !created ? 'missing' : 'passed') };
  });
  await tests.run();
  await tests.run(a);
  assert.equal(created, false);
  assert.equal(calls.filter((body) => body.operation === 'update').length, 0);
  assert.equal(tests.results.get(a).status, 'missing');
  assert.equal(tests.passed, 1);
  const unrelated = tests.results.get(b);
  const creation = await tests.update(a);
  assert.equal(creation.status, 'created');
  assert.equal(tests.results.get(b), unrelated);
  assert.equal(tests.status(a, 'variant'), 'failed');
  assert.equal(tests.passed, 1, 'Creating a snapshot must not make the test green.');
  assert.deepEqual(JSON.parse(JSON.stringify(calls.at(-1))), { site: 'preview', language: 'de', scope: a, operation: 'update' });
  await tests.run(a);
  assert.equal(tests.passed, 2);
  assert.equal(tests.status(), 'passed');
});

test('Production prevents updates without disabling comparisons', async () => {
  let calls = 0;
  const { tests } = await state(async (body) => {
    calls++;
    return body.operation === 'discover' ? { identifiers: [a], catalog: [a] } : { result: result(a, 'failed') };
  }, { snapshotReadOnly: true });
  await tests.run();
  await assert.rejects(tests.update(a), /unavailable/);
  assert.equal(calls, 2);
  assert.equal(tests.results.get(a).status, 'failed');
});

test('expired results and unknown variant updates are rejected before making a request', async () => {
  let calls = 0;
  const { tests } = await state(async () => { calls++; return {}; });
  tests.catalog = [a];
  const generation = tests.generation;
  tests.invalidate();
  await assert.rejects(tests.update(a, generation), /expired/);
  await assert.rejects(tests.update(b), /one catalog variant/);
  assert.equal(calls, 0);
});

for (const invalidResponse of [false, true]) {
  test(`${invalidResponse ? 'invalid update responses' : 'failed update requests'} retain the mismatch for a retry`, async () => {
    const { tests } = await state(async () => {
      if (invalidResponse) return { result: result(b, 'updated') };
      throw new Error('Update unavailable');
    });
    tests.catalog = [a, b];
    const previous = result(a, 'failed');
    tests.results.set(a, previous);
    await assert.rejects(tests.update(a), invalidResponse ? /Invalid snapshot response/ : /Update unavailable/);
    assert.equal(tests.results.get(a), previous);
    assert.equal(tests.active, false);
  });
}

test('application-level update errors retain the mismatch, its diff and a retryable action', async () => {
  let attempts = 0;
  const { tests } = await state(async () => ({ success: true, result: attempts++ === 0
    ? { ...result(a, 'error'), message: 'Cannot write baseline.', trace: '#0 storage write failed' }
    : result(a, 'updated') }));
  tests.catalog = [a, b];
  const previous = { ...result(a, 'failed'), diff: [{ changed: true, text: 'original diff' }] };
  const unrelated = result(b);
  tests.results.set(a, previous);
  tests.results.set(b, unrelated);
  await assert.rejects(tests.update(a), (error: any) => {
    assert.equal(error.message, 'Cannot write baseline.');
    assert.equal(error.trace, '#0 storage write failed');
    return true;
  });
  assert.equal(tests.results.get(a), previous);
  assert.equal(tests.results.get(a).diff, previous.diff);
  assert.equal(tests.results.get(b), unrelated);
  assert.equal(tests.active, false);
  assert.equal(tests.status(a, 'variant'), 'failed');
  assert.equal((await tests.update(a)).status, 'updated');
  assert.equal(attempts, 2);
});

for (const invalidate of ['context', 'files']) {
  for (const outcome of ['success', 'network error', 'application error']) {
    test(`${invalidate} changes cancel updates and ignore a late ${outcome} after a new run`, async () => {
      let settle: () => void = () => {};
      let updateSignal: AbortSignal | undefined;
      const { tests } = await state(async (body, signal) => {
        if (body.operation === 'update') {
          updateSignal = signal;
          return new Promise((resolve, reject) => {
            settle = () => outcome === 'network error' ? reject(new Error('Old update failed'))
              : resolve({ success: true, result: result(a, outcome === 'application error' ? 'error' : 'updated') });
          });
        }
        return body.operation === 'discover' ? { identifiers: [b], catalog: [a, b] } : { result: result(b) };
      });
      tests.catalog = [a];
      const updating = tests.update(a);
      await assert.rejects(tests.update(a), /Wait for the current/);
      await tests.run();
      assert.equal(tests.active, true);
      if (invalidate === 'context') tests.setContext({ site: 'preview', language: 'en' });
      else tests.invalidate();
      assert.equal(updateSignal?.aborted, true);
      await tests.run(b);
      settle();
      await updating;
      assert.deepEqual([...tests.results.keys()], [b]);
      assert.equal(tests.status(b, 'variant'), 'passed');
      assert.equal(tests.errors.size, 0);
      assert.equal(tests.active, false);
    });
  }
}

test('diff content escapes scripts, attributes, paths and error messages', async () => {
  const modules = backendModules({}, { '@typo3/backend/modal.js': {} });
  const { renderSnapshotDiff } = await modules.import('snapshot-diff.js');
  const output = renderSnapshotDiff([{
    ...result('<img src=x onerror=alert(1)>', 'failed'),
    expected: '<script>alert(1)</script>', actual: '<img src=x onerror=alert(2)>',
    path: '<svg onload=alert(3)>', message: '<iframe src=javascript:alert(4)>',
  }]);
  assert.ok(!output.includes('<script>'));
  assert.ok(!output.includes('<img'));
  assert.ok(!output.includes('<iframe'));
  assert.ok(!output.includes('<svg'));
  assert.ok(output.includes('&lt;script&gt;'));
  assert.ok(output.includes('Snapshot mismatch'));
  assert.ok(output.includes('Show full output'));
});


test('focused diffs show only nearby changes and retain separate source line numbers', async () => {
  const modules = backendModules({}, { '@typo3/backend/modal.js': {} });
  const { focusedRows, renderDiffRows } = await modules.import('snapshot-diff.js');
  const rows = Array.from({ length: 20 }, (_, index) => ({
    expected: index + 1, actual: index + 2, changed: index === 10,
    expectedSegments: [{ text: '<p>same old word</p>', changed: false }],
    actualSegments: [{ text: '<p>same ', changed: false }, { text: 'new ', changed: true }, { text: 'word</p>', changed: false }],
  }));
  const focused = focusedRows(rows);
  assert.equal(focused.filter(Boolean).length, 5);
  assert.equal(focused.filter((row: any) => row === null).length, 2);
  const output = renderDiffRows(focused);
  assert.ok(output.includes('<td>11</td><td>—</td>'));
  assert.ok(output.includes('<td>—</td><td>12</td>'));
  assert.ok(output.includes('<mark class="fs-snapshot-added">new </mark>'));
  assert.ok(!output.includes('<p>same'));
});

test('diff context counts HTML source lines when one line spans several diff rows', async () => {
  const modules = backendModules({}, { '@typo3/backend/modal.js': {} });
  const { focusedRows } = await modules.import('snapshot-diff.js');
  const rows = [
    [1, 1], [2, 2], [3, 3], [3, 4], [4, 4], [5, 5], [5, 6], [6, 7], [7, 8],
  ].map(([expected, actual], index) => ({ expected, actual, changed: index === 4 }));
  assert.deepEqual(Array.from(focusedRows(rows)), [null, ...rows.slice(1, 8), null]);
});

for (const side of ['expected', 'actual'] as const) {
  test(`inserted or removed HTML retains two context lines on both sides (${side})`, async () => {
    const modules = backendModules({}, { '@typo3/backend/modal.js': {} });
    const { focusedRows } = await modules.import('snapshot-diff.js');
    const rows: { expected: number | null; actual: number | null; changed: boolean }[] = Array.from({ length: 7 }, (_, index) => ({
      expected: index + 1, actual: index + 1, changed: index === 3,
    }));
    rows[3][side] = null;
    assert.deepEqual(Array.from(focusedRows(rows)), [null, ...rows.slice(1, 6), null]);
  });
}

test('mismatches put plain next-step guidance above the diff and use Should/Got headings', async () => {
  const modules = backendModules({}, { '@typo3/backend/modal.js': {} });
  const { renderSnapshotDiff } = await modules.import('snapshot-diff.js');
  const output = renderSnapshotDiff([{
    ...result(a, 'failed'), path: 'Card.snapshot.html',
    diff: [{ expected: 1, actual: 1, changed: true,
      expectedSegments: [{ text: 'old', changed: true }], actualSegments: [{ text: 'new', changed: true }] }],
  }]);
  assert.ok(output.indexOf('<h4>HTML changes</h4>') < output.indexOf('<h4>Next step</h4>'));
  assert.ok(output.indexOf('<h4>Next step</h4>') < output.indexOf('<table>'));
  assert.ok(output.includes('<th>Should</th><th>Got</th>'));
  assert.ok(output.includes('fs-snapshot-state text-danger'));
  assert.ok(!output.includes('alert-info'));
  assert.ok(!output.includes('Dynamic values are masked'));
  assert.ok(!output.includes('highlighted words changed'));
  assert.ok(!output.includes('Card.snapshot.html'));
});

test('missing snapshots and errors offer the appropriate next step without a fake diff', async () => {
  const modules = backendModules({}, { '@typo3/backend/modal.js': {} });
  const { failureSummary, renderSnapshotDiff, copyDetails } = await modules.import('snapshot-diff.js');
  assert.match(failureSummary({ status: 'missing' }).next, /Use Create snapshot outside Production/);
  assert.match(failureSummary({ status: 'created' }).next, /commit the new snapshot/);
  const failure = { identifier: a, status: 'error', message: '<script>bad</script>', trace: '<img onerror=bad>', path: '/html-Default@preview@en.snapshot.html' };
  const output = renderSnapshotDiff([failure]);
  assert.ok(output.includes('Test error'));
  assert.ok(!output.includes('<h4>Snapshot file</h4>'));
  assert.ok(output.includes('Stack trace'));
  assert.ok(!output.includes('<summary>Technical details</summary>'));
  assert.ok(!output.includes('<table>'));
  assert.ok(!output.includes('<script>'));
  assert.ok(!output.includes('<img'));
  const copy = copyDetails(failure, { site: 'preview', language: 'de' });
  assert.ok(copy.includes('Site: preview · Language: de'));
  assert.ok(copy.includes('/html-Default@preview@en.snapshot.html'));
  assert.ok(copy.includes(failure.trace));
});

for (const status of ['failed', 'missing', 'created', 'error', 'updated', 'passed']) {
  test(`${status} details separate the result, next step and trace`, async () => {
    const modules = backendModules({}, { '@typo3/backend/modal.js': {} });
    const { renderSnapshotDiff } = await modules.import('snapshot-diff.js');
    const output = renderSnapshotDiff([{
      ...result(a, status), path: 'Card.snapshot.html', trace: 'Error trace',
      diff: [{ expected: 1, actual: 1, changed: true,
        expectedSegments: [{ text: 'old', changed: true }], actualSegments: [{ text: 'new', changed: true }] }],
    }]);
    assert.ok(output.includes('<dt>Variant</dt>'));
    assert.ok(output.indexOf('<h4>Next step</h4>') > output.indexOf(`<dd><code>${a}</code></dd>`));
    assert.ok(!output.includes('<h4>Snapshot file</h4>'));
    assert.ok(output.indexOf('<h4>Stack trace</h4>') > output.indexOf('<h4>Next step</h4>'));
    assert.equal(output.includes('<h4>HTML changes</h4>'), status === 'failed');
    assert.ok(output.includes(`fs-snapshot-tone-${status === 'passed' ? 'success' : ['missing', 'created', 'updated'].includes(status) ? 'warning' : 'danger'}`));
  });
}

test('Production mismatch guidance explains that snapshots must be updated elsewhere', async () => {
  const modules = backendModules({}, { '@typo3/backend/modal.js': {} });
  const { renderSnapshotDiff } = await modules.import('snapshot-diff.js');
  assert.match(renderSnapshotDiff([result(a, 'failed')], true), /Update the snapshot outside Production/);
});
