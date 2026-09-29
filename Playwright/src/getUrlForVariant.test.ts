import assert from 'node:assert/strict';
import { isAbsolute, resolve } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { getUrlForVariant } from './getUrlForVariant.ts';

test('creates a component preview URL with the variant name', () => {
  const url = getUrlForVariant('Special Variant');
  const previewUrl = new URL(url, 'http://web');

  assert.match(url, /^\/__frontendStudio\/preview\?/);
  assert.equal(previewUrl.pathname, '/__frontendStudio/preview');
  assert.equal(previewUrl.searchParams.has('frontendStudioComponentPreview'), false);
  assert.equal(previewUrl.searchParams.get('componentVariantName'), 'Special Variant');
  assert.equal(previewUrl.searchParams.has('site'), false);
  assert.equal(previewUrl.searchParams.has('language'), false);
});

test('adds an optional site identifier and language hreflang', () => {
  const previewUrl = new URL(getUrlForVariant('Default', undefined, 'main-site', 'de-AT'), 'http://web');

  assert.equal(previewUrl.searchParams.get('site'), 'main-site');
  assert.equal(previewUrl.searchParams.get('language'), 'de-AT');
});

test('adds optional site and language parameters independently', () => {
  const siteOnlyUrl = new URL(getUrlForVariant('Default', undefined, 'main-site'), 'http://web');
  const languageOnlyUrl = new URL(getUrlForVariant('Default', undefined, undefined, 'de-AT'), 'http://web');

  assert.equal(siteOnlyUrl.searchParams.get('site'), 'main-site');
  assert.equal(siteOnlyUrl.searchParams.has('language'), false);
  assert.equal(languageOnlyUrl.searchParams.has('site'), false);
  assert.equal(languageOnlyUrl.searchParams.get('language'), 'de-AT');
});

test('encodes site and language values as query parameter values', () => {
  const siteIdentifier = 'special & site + ?';
  const languageHreflang = 'de-AT-x-test';
  const previewUrl = new URL(getUrlForVariant('Default', undefined, siteIdentifier, languageHreflang), 'http://web');

  assert.equal(previewUrl.searchParams.get('site'), siteIdentifier);
  assert.equal(previewUrl.searchParams.get('language'), languageHreflang);
});

test('includes the caller file as an absolute componentPath parameter', () => {
  const previewUrl = new URL(getUrlForVariant('Default'), 'http://web');
  const componentPath = previewUrl.searchParams.get('componentPath')!;

  assert.doesNotMatch(componentPath, /^file:/);
  assert.equal(isAbsolute(componentPath), true);
  assert.match(componentPath, /getUrlForVariant\.test\.ts$/);
});

test('converts a file URL passed as componentPath to an absolute filesystem path', () => {
  const previewUrl = new URL(getUrlForVariant('Default', import.meta.url), 'http://web');

  assert.equal(previewUrl.searchParams.get('componentPath'), fileURLToPath(import.meta.url));
});

test('preserves an explicit absolute componentPath', () => {
  const componentPath = resolve('Components/Text/Text.spec.ts');
  const previewUrl = new URL(getUrlForVariant('Default', componentPath), 'http://web');

  assert.equal(previewUrl.searchParams.get('componentPath'), componentPath);
});

test('encodes variant names as query parameter values', () => {
  const variantName = 'Special & Variant + Ü?';
  const previewUrl = new URL(getUrlForVariant(variantName), 'http://web');

  assert.equal(previewUrl.searchParams.get('componentVariantName'), variantName);
});
