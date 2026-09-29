import type { VariantMetadata } from './variantMetadata.d.ts';
import type { Page } from 'playwright-core';
import { expect, test } from '@playwright/test';
import { checkAccessibility } from './checkAccessibility.ts';
import { consoleLogging } from './consoleLogging.ts';

interface PartialSettings {
  selector: string;
  date: Date | string;
  accessibilityTags: string[];
  viewportSize: {
    width: number;
    height: number;
  };
}
export interface SnapshotTestSettings extends Partial<PartialSettings> {
  variant: VariantMetadata;
  axeFolder?: boolean;
}

interface Settings extends PartialSettings {
  variant: VariantMetadata;
  date: Date;
  axeFolder: boolean;
}

function getSettings(inputSettings: SnapshotTestSettings): Settings {
  const settings = {
    date: '2038-01-19T03:14:07',
    accessibilityTags: ['wcag22aa', 'wcag22a', 'wcag21aa', 'wcag21a', 'wcag2aa', 'wcag2a', 'best-practice'],
    viewportSize: { width: 1280, height: 800 },
    axeFolder: true,
    selector: '#rendered-component',
    ...inputSettings,
  };
  if (!('selector' in settings)) {
    throw new Error('No selector provided');
  }
  if (typeof settings.selector !== 'string' || settings.selector.trim() === '') {
    throw new Error('No selector provided');
  }
  const date = new Date(settings.date);

  return {
    variant: settings.variant,
    selector: settings.selector,
    accessibilityTags: settings.accessibilityTags,
    viewportSize: settings.viewportSize,
    axeFolder: settings.axeFolder,
    date,
  } satisfies Settings;
}

function setSnapshotPathTemplate(snapshotPathTemplate: string) {
  (test.info() as unknown as { _projectInternal: { snapshotPathTemplate: string } })._projectInternal.snapshotPathTemplate = snapshotPathTemplate;
}

export async function snapshotTest(page: Page, variant: SnapshotTestSettings) {
  const logging = consoleLogging(page);
  const settings = getSettings(variant);
  await page.setViewportSize(settings.viewportSize);

  await page.clock.setFixedTime(settings.date);
  await page.clock.pauseAt(settings.date);

  const response = await page.goto(settings.variant.url);
  expect(response?.status(), 'Expected a 200 response from the Frontend Studio preview endpoint').toBe(200);

  setSnapshotPathTemplate(`${settings.variant.fileName}-snapshots/{arg}{-projectName}{-snapshotSuffix}{ext}`);

  await expect(page.locator(settings.selector)).toMatchAriaSnapshot();
  await expect(page.locator(settings.selector)).toHaveScreenshot();

  await logging.assertEmpty();

  await page.clock.resume();

  await checkAccessibility(page, settings.selector, settings.accessibilityTags, settings.axeFolder);
}
