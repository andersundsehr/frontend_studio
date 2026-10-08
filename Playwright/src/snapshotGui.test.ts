import assert from 'node:assert/strict';
import { test } from 'node:test';
import { backendModules } from './backendModules.ts';

const a = 'site:folder.card:Default';
const b = 'site:folder.card:Second';
const c = 'site:other:Default';
async function state(request: (body: any, signal: AbortSignal) => Promise<any>) {
  const modules = backendModules();
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
  const pending = new Promise<void>((resolve) => { started = resolve; });
  const { tests } = await state(async (body) => {
    if (body.operation === 'discover') return { identifiers: [a], catalog: [a] };
    started();
    return new Promise((resolve) => { release = resolve; });
  });
  const run = tests.run();
  await pending;
  tests.invalidate();
  release({ result: result(a) });
  await run;
  assert.equal(tests.results.size, 0);
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

test('missing snapshots and errors offer the appropriate next step without a fake diff', async () => {
  const modules = backendModules({}, { '@typo3/backend/modal.js': {} });
  const { failureSummary, renderSnapshotDiff, copyDetails } = await modules.import('snapshot-diff.js');
  assert.match(failureSummary({ status: 'missing', message: 'Created baseline. Review it.' }).next, /commit the new snapshot/);
  assert.match(failureSummary({ status: 'missing', message: 'Creation blocked in Production.' }).next, /outside Production/);
  const failure = { identifier: a, status: 'error', message: '<script>bad</script>', trace: '<img onerror=bad>', path: '/html-Default@preview@en.snapshot.html' };
  const output = renderSnapshotDiff([failure]);
  assert.ok(output.includes('Test error'));
  assert.ok(output.includes('<h4>Snapshot file</h4>'));
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

for (const status of ['failed', 'missing', 'error']) {
  test(`${status} details separate the result, next step, snapshot file and trace`, async () => {
    const modules = backendModules({}, { '@typo3/backend/modal.js': {} });
    const { renderSnapshotDiff } = await modules.import('snapshot-diff.js');
    const output = renderSnapshotDiff([{
      ...result(a, status), path: 'Card.snapshot.html', trace: 'Error trace',
      diff: [{ expected: 1, actual: 1, changed: true,
        expectedSegments: [{ text: 'old', changed: true }], actualSegments: [{ text: 'new', changed: true }] }],
    }]);
    assert.ok(output.includes('<dt>Variant</dt>'));
    assert.ok(output.indexOf('<h4>Next step</h4>') > output.indexOf(`<dd><code>${a}</code></dd>`));
    assert.ok(output.indexOf('<h4>Snapshot file</h4>') > output.indexOf('<h4>Next step</h4>'));
    assert.ok(output.indexOf('<h4>Stack trace</h4>') > output.indexOf('<h4>Snapshot file</h4>'));
    assert.equal(output.includes('<h4>HTML changes</h4>'), status === 'failed');
  });
}
