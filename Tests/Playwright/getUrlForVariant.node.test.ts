import { expect, test } from 'vitest';
import { isAbsolute, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { getUrlForVariant } from './getUrlForVariant.node';

test('creates a component preview URL with the variant name', () => {
  const url = getUrlForVariant('Special Variant');
  const previewUrl = new URL(url, 'http://web');

  expect(url).toMatch(/^\/__frontendStudio\/preview\?/);
  expect(previewUrl.pathname).toBe('/__frontendStudio/preview');
  expect(previewUrl.searchParams.has('frontendStudioComponentPreview')).toBe(false);
  expect(previewUrl.searchParams.get('componentVariantName')).toBe('Special Variant');
  expect(previewUrl.searchParams.has('site')).toBe(false);
  expect(previewUrl.searchParams.has('language')).toBe(false);
});

test('adds an optional site identifier and language hreflang', () => {
  const previewUrl = new URL(getUrlForVariant('Default', undefined, 'main-site', 'de-AT'), 'http://web');

  expect(previewUrl.searchParams.get('site')).toBe('main-site');
  expect(previewUrl.searchParams.get('language')).toBe('de-AT');
});

test('adds optional site and language parameters independently', () => {
  const siteOnlyUrl = new URL(getUrlForVariant('Default', undefined, 'main-site'), 'http://web');
  const languageOnlyUrl = new URL(getUrlForVariant('Default', undefined, undefined, 'de-AT'), 'http://web');

  expect(siteOnlyUrl.searchParams.get('site')).toBe('main-site');
  expect(siteOnlyUrl.searchParams.has('language')).toBe(false);
  expect(languageOnlyUrl.searchParams.has('site')).toBe(false);
  expect(languageOnlyUrl.searchParams.get('language')).toBe('de-AT');
});

test('encodes site and language values as query parameter values', () => {
  const siteIdentifier = 'special & site + ?';
  const languageHreflang = 'de-AT-x-test';
  const previewUrl = new URL(getUrlForVariant('Default', undefined, siteIdentifier, languageHreflang), 'http://web');

  expect(previewUrl.searchParams.get('site')).toBe(siteIdentifier);
  expect(previewUrl.searchParams.get('language')).toBe(languageHreflang);
});

test('includes the caller file as an absolute componentPath parameter', () => {
  const previewUrl = new URL(getUrlForVariant('Default'), 'http://web');
  const componentPath = previewUrl.searchParams.get('componentPath')!;

  expect(componentPath).not.toMatch(/^file:/);
  expect(isAbsolute(componentPath)).toBe(true);
  expect(componentPath).toMatch(/getUrlForVariant\.node\.test\.ts$/);
});

test('converts a file URL passed as componentPath to an absolute filesystem path', () => {
  const previewUrl = new URL(getUrlForVariant('Default', import.meta.url), 'http://web');

  expect(previewUrl.searchParams.get('componentPath')).toBe(fileURLToPath(import.meta.url));
});

test('preserves an explicit absolute componentPath', () => {
  const componentPath = resolve('Components/Text/Text.spec.ts');
  const previewUrl = new URL(getUrlForVariant('Default', componentPath), 'http://web');

  expect(previewUrl.searchParams.get('componentPath')).toBe(componentPath);
});

test('encodes variant names as query parameter values', () => {
  const variantName = 'Special & Variant + Ü?';
  const previewUrl = new URL(getUrlForVariant(variantName), 'http://web');

  expect(previewUrl.searchParams.get('componentVariantName')).toBe(variantName);
});
