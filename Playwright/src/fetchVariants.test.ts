import assert from 'node:assert/strict';
import { test } from 'node:test';
import { fetchVariants } from './fetchVariants.ts';

const variant = {
  url: '/__frontendStudio/preview?componentVariant=example',
  componentName: 'Text',
  phpNamespace: 'Vendor\\Site',
  variantName: 'Default',
  fileName: 'Text/Default.fluid.html',
};

void test('fetches variants from the origin root', async (context) => {
  const requestedUrls: string[] = [];
  context.mock.method(globalThis, 'fetch', (url: Parameters<typeof fetch>[0]) => {
    requestedUrls.push(String(url));
    return Promise.resolve(Response.json({ variants: [variant] }));
  });

  assert.deepEqual(await fetchVariants('http://localhost:8080'), [variant]);
  assert.deepEqual(await fetchVariants('http://localhost:8080/site/'), [variant]);
  assert.deepEqual(requestedUrls, ['http://localhost:8080/__frontendStudio/variants', 'http://localhost:8080/__frontendStudio/variants']);
});

void test('accepts an empty variant list', async (context) => {
  context.mock.method(globalThis, 'fetch', () => Promise.resolve(Response.json({ variants: [] })));

  assert.deepEqual(await fetchVariants('http://localhost'), []);
});

void test('rejects an unsuccessful response', async (context) => {
  context.mock.method(globalThis, 'fetch', () => Promise.resolve(Response.json({}, { status: 503, statusText: 'Service Unavailable' })));
  context.mock.method(console, 'error', () => undefined);

  await assert.rejects(fetchVariants('http://localhost'), {
    message: 'Failed to fetch http://localhost/__frontendStudio/variants : Service Unavailable',
  });
});

void test('rethrows a network failure', async (context) => {
  const failure = new Error('connection refused');
  context.mock.method(globalThis, 'fetch', () => Promise.reject(failure));
  context.mock.method(console, 'error', () => undefined);

  await assert.rejects(fetchVariants('http://localhost'), (error) => error === failure);
});

void test('rejects missing or invalid variant lists', async (context) => {
  let payload: unknown;
  context.mock.method(globalThis, 'fetch', () => Promise.resolve(Response.json(payload)));

  for (payload of [{}, { variants: null }, { variants: {} }]) {
    await assert.rejects(fetchVariants('http://localhost'), /Invalid response/);
  }
});

void test('rejects non-string variant metadata', async (context) => {
  let metadata: Record<string, unknown> = variant;
  context.mock.method(globalThis, 'fetch', () => Promise.resolve(Response.json({ variants: [metadata] })));

  for (const field of Object.keys(variant)) {
    metadata = { ...variant, [field]: 42 };
    await assert.rejects(fetchVariants('http://localhost'), /Invalid variant metadata/);
  }
});

void test('rejects malformed JSON', async (context) => {
  context.mock.method(globalThis, 'fetch', () => Promise.resolve(new Response('not JSON')));

  await assert.rejects(fetchVariants('http://localhost'), SyntaxError);
});
