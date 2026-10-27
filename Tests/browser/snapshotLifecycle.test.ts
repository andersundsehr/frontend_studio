import assert from 'node:assert/strict';
import test from 'node:test';
import { backendModules } from '../../Playwright/src/backendModules.ts';

const identifier = 'site:card:Default';

for (const status of ['created', 'updated', 'unchanged']) {
  test(`${status} snapshots stay yellow until a regular test passes`, async () => {
    const modules = backendModules({}, { '@typo3/backend/modal.js': {} });
    const { default: SnapshotTests } = await modules.import('snapshot-tests.js');
    const { failureSummary, renderSnapshotSummary } = await modules.import('snapshot-diff.js');
    let verified = false;
    const tests = new SnapshotTests(() => {}, async (body: any) => {
      if (body.operation === 'discover') return { identifiers: [identifier], catalog: [identifier] };
      return { result: { identifier, status: body.operation === 'update' ? status : verified ? 'passed' : 'missing' } };
    });
    tests.setContext({ site: 'preview', language: 'de' });
    await tests.run();
    const result = await tests.update(identifier);
    assert.equal(result.status, status);
    assert.equal(tests.passed, 0);
    assert.equal(tests.status(identifier, 'variant'), 'failed');
    assert.equal(failureSummary(result).tone, 'warning');
    assert.match(failureSummary(result).next, /rerun|Rerun/);
    assert.match(renderSnapshotSummary(result), /fs-snapshot-tone-warning/);
    verified = true;
    await tests.run();
    assert.equal(tests.passed, 1);
    assert.equal(tests.status(identifier, 'variant'), 'passed');
  });
}
