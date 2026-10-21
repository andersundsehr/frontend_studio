import assert from 'node:assert/strict';
import test from 'node:test';
import { backendModules } from '../../Playwright/src/backendModules.ts';

const identifiers = Array.from({ length: 7 }, (_, index) => `site:card:Variant${index}`);
const tick = () => new Promise(setImmediate);

async function setup(catalog = identifiers) {
  const requests: Array<{
    body: { scope: string; site: string; language: string };
    signal: AbortSignal;
    resolve: (payload: unknown) => void;
    reject: (error: Error) => void;
  }> = [];
  const modules = backendModules();
  const { default: SnapshotTests } = await modules.import('snapshot-tests.js');
  const tests = new SnapshotTests(() => {}, async (body: any, signal: AbortSignal) => {
    if (body.operation === 'discover') return { identifiers: catalog, catalog };
    return new Promise((resolve, reject) => requests.push({ body, signal, resolve, reject }));
  });
  tests.setContext({ site: 'preview', language: 'de' });
  const respond = async (index: number, status = 'passed') => {
    requests[index].resolve({ result: { identifier: requests[index].body.scope, status } });
    await tick();
  };
  return { tests, requests, respond };
}

test('Test all keeps three requests running and reports out-of-order successes and failures immediately', async () => {
  const { tests, requests, respond } = await setup();
  const running = tests.run();
  await tick();
  assert.deepEqual(requests.map(request => request.body.scope), identifiers.slice(0, 3));
  assert.equal(tests.total, 7);
  assert.equal(tests.completed, 0);
  assert.equal(tests.active, true);
  await tests.run();
  assert.equal(requests.length, 3, 'Repeated clicks must not start another run.');

  await respond(1);
  assert.equal(requests.length, 4, 'A free slot must start the next variant without waiting for the others.');
  assert.equal(tests.completed, 1);
  assert.equal(tests.passed, 1);
  assert.equal(tests.results.get(identifiers[1]).status, 'passed');
  requests[2].reject(new Error('Network unavailable'));
  await tick();
  assert.equal(requests.length, 5);
  assert.equal(tests.completed, 2);
  assert.equal(tests.results.get(identifiers[2]).message, 'Network unavailable');
  await respond(3, 'missing');
  assert.equal(requests.length, 6);
  assert.equal(tests.completed, 3);
  assert.equal(tests.passed, 1);
  await respond(0);
  assert.equal(requests.length, 7);
  assert.equal(tests.completed, 4);
  assert.equal(tests.active, true);
  await respond(6);
  await respond(4);
  assert.equal(tests.active, true, 'The run must remain active until the last request finishes.');
  await respond(5);
  await running;
  assert.deepEqual(requests.map(request => request.body.scope), identifiers);
  assert.equal(tests.completed, 7);
  assert.equal(tests.passed, 5);
  assert.equal(tests.active, false);
  assert.deepEqual(Array.from(tests.failures(), (failure: any) => failure.identifier), [identifiers[2], identifiers[3]]);
});

for (const change of ['context', 'files']) {
  for (const staleFailure of [false, true]) {
    test(`${change} changes abort all requests, discard queued variants and ignore late ${staleFailure ? 'errors' : 'results'}`, async () => {
      const { tests, requests, respond } = await setup();
      const cancelled = tests.run();
      await tick();
      assert.equal(requests.length, 3);
      if (change === 'context') tests.setContext({ site: 'preview', language: 'en' });
      else tests.invalidate();
      assert.ok(requests.every(request => request.signal.aborted));
      assert.equal(tests.results.size, 0);
      assert.equal(tests.catalog.length, 0);

      const current = tests.run();
      await tick();
      assert.equal(requests.length, 6);
      for (const request of requests.slice(0, 3)) {
        if (staleFailure) request.reject(new Error('Cancelled request failed'));
        else request.resolve({ result: { identifier: request.body.scope, status: 'failed' } });
      }
      await cancelled;
      assert.equal(requests.length, 6, 'Cancelled workers must not start queued variants.');
      assert.equal(tests.completed, 0);
      assert.equal(tests.results.size, 0);
      assert.equal(tests.errors.size, 0);
      assert.equal(tests.active, true, 'Late completions must not finish the newer run.');
      for (let index = 3; index < requests.length; index++) await respond(index);
      await current;
      assert.equal(requests.length, 10);
      assert.ok(requests.slice(3).every(request => !request.signal.aborted && request.body.language === (change === 'context' ? 'en' : 'de')));
      assert.equal(tests.completed, 7);
      assert.equal(tests.passed, 7);
      assert.equal(tests.active, false);
    });
  }
}

test('scoped tests still run one request at a time', async () => {
  const { tests, requests, respond } = await setup(identifiers.slice(0, 3));
  const running = tests.run('site:card');
  await tick();
  assert.equal(requests.length, 1);
  await respond(0);
  assert.equal(requests.length, 2);
  await respond(1);
  assert.equal(requests.length, 3);
  await respond(2);
  await running;
  assert.equal(tests.passed, 3);
  assert.equal(tests.active, false);
});

test('Test all finishes an empty catalog without starting variant requests', async () => {
  const { tests, requests } = await setup([]);
  await tests.run();
  assert.equal(requests.length, 0);
  assert.equal(tests.completed, 0);
  assert.equal(tests.total, 0);
  assert.equal(tests.active, false);
});
