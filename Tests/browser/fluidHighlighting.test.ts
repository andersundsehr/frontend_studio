import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

type Token = [string | null, string];
type Node = { type: string; value?: string; tagName?: string; properties?: { className: string[] }; children?: Node[] };
const fixtures: Array<{ name: string; source: string; tokens: Token[] }> = JSON.parse(await readFile(new URL('../Unit/Service/Fixtures/fluid-highlighting.json', import.meta.url), 'utf8'));
const bundle = await readFile(new URL('../../Resources/Public/Contrib/code-highlighting.js', import.meta.url), 'utf8');
const { lowlight, highlightCode, codeLanguages, getCodeLanguageLabel, default: highlight } = await import(`data:text/javascript;base64,${Buffer.from(bundle).toString('base64')}`);
const prefix = 'frontend-studio-variant-html-source__';

function tokens(root: Node): Token[] {
  const result: Token[] = [];
  const visit = (node: Node, token: string | null = null) => {
    if (node.type === 'text') {
      const previous = result.at(-1);
      if (previous && previous[0] === token) previous[1] += node.value!;
      else if (node.value) result.push([token, node.value]);
    } else {
      if (node.type === 'element') {
        assert.equal(node.tagName, 'span', 'source markup must remain text');
        const classes = node.properties!.className;
        assert.equal(classes.length, 1);
        assert.ok(classes[0].startsWith(prefix));
        token = classes[0].slice(prefix.length);
      }
      node.children!.forEach(child => visit(child, token));
    }
  };
  visit(root);
  return result;
}

for (const fixture of fixtures) {
  test(`Fluid matches PHP token classification and preserves source: ${fixture.name}`, () => {
    const rendered = tokens(highlightCode(fixture.source, 'fluid'));
    const editor = tokens(lowlight.highlight('fluid', fixture.source));
    assert.deepEqual(rendered, fixture.tokens);
    assert.deepEqual(editor, rendered);
    assert.equal(rendered.map(([, value]) => value).join(''), fixture.source);
  });
}

test('Fluid is a separate selectable language while HTML/XML, aliases and exports stay available', () => {
  assert.deepEqual(codeLanguages.filter((language: any) => ['html', 'fluid'].includes(language.value)), [
    { value: 'fluid', label: 'Fluid' }, { value: 'html', label: 'HTML' },
  ]);
  assert.ok(lowlight.listLanguages().includes('fluid'));
  assert.equal(lowlight.registered('fluid'), true);
  assert.equal(getCodeLanguageLabel('fluid'), 'Fluid');
  assert.equal(getCodeLanguageLabel('html'), 'HTML');
  assert.equal(getCodeLanguageLabel('xml'), 'HTML');
  assert.equal(typeof highlight.getLanguage, 'function');
  const source = '<div title="{title}">{content->f:format.raw()}</div>';
  assert.deepEqual(highlightCode(source, 'html').children, highlightCode(source, 'xml').children);
  assert.ok(!JSON.stringify(highlightCode(source, 'html')).includes(prefix));
  for (const alias of ['js', 'ts', 'sh', 'yml']) assert.equal(lowlight.registered(alias), true);
  assert.ok(JSON.stringify(highlightCode('const value = 1;', 'js')).includes('hljs-keyword'));
});

test('unspecified and unknown languages remain plain text without guessing Fluid', () => {
  const source = '<ui:Card title="{title}" />';
  const plain = { type: 'root', children: [{ type: 'text', value: source }] };
  for (const language of ['', 'unknown-language', undefined]) assert.deepEqual(highlightCode(source, language), plain);
  assert.deepEqual(lowlight.highlightAuto(source), plain);
});

test('deeply nested and incomplete expressions remain safe to highlight while typing', () => {
  for (const source of ['{'.repeat(4096) + 'value' + '}'.repeat(4096), '{'.repeat(4096) + 'unfinished']) {
    assert.equal(tokens(highlightCode(source, 'fluid')).map(([, value]) => value).join(''), source);
  }
});
