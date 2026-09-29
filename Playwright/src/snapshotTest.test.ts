import assert from 'node:assert/strict';
import { test } from 'node:test';
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
