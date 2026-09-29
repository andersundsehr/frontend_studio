import { expect, type Page, test } from '@playwright/test';
import { createFilterFunction, writeIgnoreFile } from './ignoreFile.ts';

/**
 * usage:
 * ```typescript
 * import { consoleLogging } from '@andersundsehr/playwright-addon';
 *
 * const logs = consoleLogging(page);
 *
 * // ... your test code ...
 *
 * await logs.assertEmpty();
 * ```
 */
export function consoleLogging(page: Page) {
  const logs: string[] = [];
  page.on('console', (message) => {
    logs.push(`${message.type()}➡️ ${message.text()}`);
  });
  return {
    async assertEmpty() {
      const update = ['all', 'changed'].includes(test.info().config.updateSnapshots);
      if (update) {
        await writeIgnoreFile(logs, '.consoleignore');
        expect([], 'no console outputs').toEqual([]);
        return;
      }

      const filterFunction = await createFilterFunction('.consoleignore');
      const actualLogs = logs.filter(filterFunction);
      expect(actualLogs, 'no console outputs').toEqual([]);
    },
  };
}
