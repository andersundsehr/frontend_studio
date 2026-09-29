import AxeBuilder from '@axe-core/playwright';
import { createHtmlReport } from 'axe-html-reporter';
import { mkdir, writeFile } from 'node:fs/promises';
import { dirname } from 'node:path';
import { prettyPrintAxeReport } from 'axe-result-pretty-print';
import { expect, type Page, test } from '@playwright/test';
import { createFilterFunction, writeIgnoreFile } from './ignoreFile.ts';
import { deleteUpwards } from './deleteUpwards.ts';

export async function checkAccessibility(
  page: Page,
  selector: string | null = null,
  accessibilityTags = ['wcag22aa', 'wcag22a', 'wcag21aa', 'wcag21a', 'wcag2aa', 'wcag2a', 'best-practice'],
  axeFolder = false,
) {
  await test.step('Accessibility', async () => {
    // generate accessibility report
    const axeBuilder = new AxeBuilder({ page });
    axeBuilder.withTags(accessibilityTags);
    if (selector) {
      axeBuilder.include(selector);
    }
    // did you use page.clock. functions? if so, try using await page.clock.resume();
    const accessibilityScanResults = await axeBuilder.analyze();

    const reportFileName = test
      .info()
      .snapshotPath()
      .replace(/\.txt$/, '.html')
      .replace('-snapshots', '-axe-report');

    const update = ['all', 'changed'].includes(test.info().config.updateSnapshots);
    const violationToString = (v: { id: string; nodes: unknown[]; description: string }) => `${v.id}: ${v.nodes.length} - ${v.description}`;
    if (update) {
      const validations = accessibilityScanResults.violations.map(violationToString);
      await writeIgnoreFile(validations, '.accessibilityignore');
      if (axeFolder) {
        await deleteUpwards(reportFileName);
      }
      return;
    }

    const filterFunction = await createFilterFunction('.accessibilityignore');
    accessibilityScanResults.violations = accessibilityScanResults.violations.filter((v) => filterFunction(violationToString(v)));

    if (accessibilityScanResults.violations.length <= 0) {
      if (axeFolder) {
        await deleteUpwards(reportFileName);
      }
      return;
    }

    // create HTML Report for violations
    const html = createHtmlReport({
      results: accessibilityScanResults,
      options: {
        // outputDir,
        // reportFileName,
        doNotCreateReportFile: true,
      },
    });

    if (axeFolder) {
      await mkdir(dirname(reportFileName), { recursive: true });
      await writeFile(reportFileName, html);
    }

    // attach report to report
    await test.info().attach('axe-report.html', {
      body: html,
      contentType: 'text/html',
    });

    // print report to console
    prettyPrintAxeReport(accessibilityScanResults);

    expect(accessibilityScanResults.violations.map(violationToString)).toEqual([]);
  });
}
