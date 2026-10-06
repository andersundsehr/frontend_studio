import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { createRequire } from 'node:module';

const { DOMParser } = createRequire(import.meta.url)('linkedom');

Object.assign(globalThis, { window: { document: new DOMParser().parseFromString('<html></html>', 'text/html'), DOMParser } });

const source = await readFile(new URL('../../Resources/Public/JavaScript/Backend/markdown-editor.bundle.js', import.meta.url), 'utf8');
const editor = await import(`data:text/javascript;base64,${Buffer.from(source).toString('base64')}`);

test('Markdown rendering escapes raw HTML and rejects executable URLs', () => {
  const html = editor.renderMarkdown('<script>alert(1)</script>\n\n[x](javascript:alert%281%29)\n\n<img src=x onerror=alert(1)>');
  assert.ok(!html.includes('<script>'));
  assert.ok(!html.includes('<img'));
  assert.ok(!html.includes('href="javascript:'));
  assert.ok(html.includes('&lt;script&gt;'));
});

test('rich text accepts its supported Markdown and leaves lossy documents in source mode', () => {
  for (const markdown of ['', 'Paragraph', '# Heading\n\nParagraph', '* one\n* two', '1. one\n2. two', '[Example](https://example.org)', '```\ncode\n```']) {
    assert.equal(editor.canEditRichText(markdown), true, markdown);
  }
  for (const markdown of ['Paragraph\n', '<custom>HTML</custom>', '[x][ref]\n\n[ref]: https://example.org', '| one | two |\n| --- | --- |']) {
    assert.equal(editor.canEditRichText(markdown), false, markdown);
  }
});


test('CKEditor plaintext code blocks retain unannotated fences and escape embedded fences', () => {
  assert.equal(editor.toMarkdown('<pre><code class="language-plaintext">code\n</code></pre>'), '```\ncode\n```');
  assert.equal(editor.toMarkdown('<pre><code class="language-plaintext">```\n</code></pre>'), '````\n```\n````');
});
