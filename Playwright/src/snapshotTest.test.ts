import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { mkdir, mkdtemp, readdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import type { Page } from 'playwright-core';
import { snapshotTest } from './snapshotTest.ts';

for (const status of [404, 500]) {
  test(`rejects a preview response with HTTP ${status} before checking snapshots`, async () => {
    const page = {
      on: () => undefined,
      setViewportSize: async () => undefined,
      clock: {
        setFixedTime: async () => undefined,
        pauseAt: async () => undefined,
      },
      goto: async () => ({ status: () => status }),
    } as unknown as Page;

    await assert.rejects(snapshotTest(page, {
      variant: {
        url: '/__frontendStudio/preview?componentVariant=example',
        componentName: 'Text',
        phpNamespace: 'Vendor\\Site',
        variantName: 'Default',
        fileName: 'Text/Default.fluid.html',
      },
    }), new RegExp(`Received: ${status}`));
  });
}

function createPlaywrightProject(root: string): { config: string; spec: string } {
  const playwrightTest = JSON.stringify(fileURLToPath(import.meta.resolve('@playwright/test')));
  const snapshotTestModule = JSON.stringify(fileURLToPath(import.meta.resolve('./snapshotTest.ts')));
  const variant = JSON.stringify({
    url: 'http://preview.test/text',
    componentName: 'Text',
    phpNamespace: 'Vendor\\Site',
    variantName: 'Default',
    fileName: join(root, 'Components', 'Text', 'Default.fluid.html'),
  });

  return {
    config: `export default {
  testDir: './tests',
  outputDir: './test-results',
  workers: 1,
  reporter: 'line',
  expect: { timeout: 1000 },
  use: process.env.CHROMIUM_PATH ? { launchOptions: { executablePath: process.env.CHROMIUM_PATH } } : {},
};
`,
    spec: `import { expect, test } from ${playwrightTest};
import { snapshotTest } from ${snapshotTestModule};

test.beforeEach(async ({ page }) => {
  await page.route('http://preview.test/**', (route) => route.fulfill({
    contentType: 'text/html',
    body: '<html lang="en"><body><main id="rendered-component"><img src="data:,"></main>'
      + '<script>console.log("component log")</script></body></html>',
  }));
});

test('Text Default', async ({ page }) => {
  const snapshotPath = test.info().snapshotPath('probe.txt');
  await snapshotTest(page, { variant: ${variant} });
  expect(test.info().snapshotPath('probe.txt')).toBe(snapshotPath);
});

test('Text Default with a failing check', async ({ page }) => {
  const snapshotPath = test.info().snapshotPath('probe.txt');
  await expect(snapshotTest(page, { variant: ${variant}, selector: '#missing' })).rejects.toThrow();
  expect(test.info().snapshotPath('probe.txt')).toBe(snapshotPath);
});
`,
  };
}

function runPlaywright(root: string, ...args: string[]): { status: number | null; output: string } {
  const result = spawnSync(
    process.execPath,
    [fileURLToPath(import.meta.resolve('@playwright/test/cli')), 'test', '--config', join(root, 'playwright.config.mjs'), ...args],
    { cwd: root, encoding: 'utf8' },
  );
  return { status: result.status, output: result.stdout + result.stderr };
}

test('keeps ignore files beside the component and restores the snapshot path template', async (context) => {
  const root = await mkdtemp(join(tmpdir(), 'frontend-studio-snapshot-'));
  context.after(() => rm(root, { recursive: true, force: true }));
  const project = createPlaywrightProject(root);
  await mkdir(join(root, 'tests'));
  await writeFile(join(root, 'playwright.config.mjs'), project.config);
  await writeFile(join(root, 'tests', 'component.spec.ts'), project.spec);

  const update = runPlaywright(root, '--update-snapshots');
  assert.equal(update.status, 0, update.output);

  const componentSnapshots = await readdir(join(root, 'Components', 'Text', 'Default.fluid.html-snapshots'));
  assert.ok(componentSnapshots.some((file) => file.endsWith('.consoleignore')), componentSnapshots.join(', '));
  assert.ok(componentSnapshots.some((file) => file.endsWith('.accessibilityignore')), componentSnapshots.join(', '));
  assert.equal(existsSync(join(root, 'tests', 'component.spec.ts-snapshots')), false);

  // Without the ignore files written above, the console log and the missing alt text fail this run.
  const check = runPlaywright(root);
  assert.equal(check.status, 0, check.output);
});
