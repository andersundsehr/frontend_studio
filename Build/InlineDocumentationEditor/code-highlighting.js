import { createLowlight } from 'lowlight';
import highlight from 'highlight.js/lib/core';
import plaintext from 'highlight.js/lib/languages/plaintext';
import xml from 'highlight.js/lib/languages/xml';
import javascript from 'highlight.js/lib/languages/javascript';
import typescript from 'highlight.js/lib/languages/typescript';
import css from 'highlight.js/lib/languages/css';
import scss from 'highlight.js/lib/languages/scss';
import php from 'highlight.js/lib/languages/php';
import json from 'highlight.js/lib/languages/json';
import yaml from 'highlight.js/lib/languages/yaml';
import bash from 'highlight.js/lib/languages/bash';
import sql from 'highlight.js/lib/languages/sql';
import markdown from 'highlight.js/lib/languages/markdown';
import twig from 'highlight.js/lib/languages/twig';
import diff from 'highlight.js/lib/languages/diff';
import ini from 'highlight.js/lib/languages/ini';
import dockerfile from 'highlight.js/lib/languages/dockerfile';
import python from 'highlight.js/lib/languages/python';
import { highlightFluid } from './fluid-highlighting.js';

const languages = [
  ['html', 'HTML', xml, ['xml']],
  ['javascript', 'JavaScript', javascript, ['js', 'jsx']],
  ['typescript', 'TypeScript', typescript, ['ts', 'tsx']],
  ['css', 'CSS', css, []],
  ['scss', 'SCSS', scss, []],
  ['php', 'PHP', php, []],
  ['json', 'JSON', json, []],
  ['yaml', 'YAML', yaml, ['yml']],
  ['bash', 'Shell', bash, ['sh', 'shell']],
  ['sql', 'SQL', sql, []],
  ['markdown', 'Markdown', markdown, ['md']],
  ['twig', 'Twig', twig, []],
  ['ini', 'INI', ini, []],
  ['dockerfile', 'Dockerfile', dockerfile, ['docker']],
  ['python', 'Python', python, ['py']],
  ['diff', 'Diff', diff, ['patch']],
];
const engine = createLowlight({ plaintext, ...Object.fromEntries(languages.map(([value, , grammar]) => [value, grammar])) });
for (const [value, , , aliases] of languages) engine.registerAlias(value, aliases);

const plain = value => ({ type: 'root', children: [{ type: 'text', value }] });
// Unannotated and unknown fences must not acquire a guessed language or formatting.
export const lowlight = {
  ...engine,
  listLanguages: () => [...engine.listLanguages(), 'fluid'],
  registered: language => language.toLowerCase() === 'fluid' || engine.registered(language),
  highlight: (language, value, options) => language.toLowerCase() === 'fluid' ? highlightFluid(value) : engine.highlight(language, value, options),
  highlightAuto: plain,
};
export const codeLanguages = [{ value: '', label: 'Plain text' }, { value: 'fluid', label: 'Fluid' }, ...languages.map(([value, label]) => ({ value, label }))];
const labels = new Map([['fluid', 'Fluid'], ['plaintext', 'Plain text'], ['text', 'Plain text'], ['txt', 'Plain text'],
  ...languages.flatMap(([value, label, , aliases]) => [value, ...aliases].map(alias => [alias, label]))]);
export const getCodeLanguageLabel = value => labels.get(value?.toLowerCase()) || value || 'Plain text';
export function highlightCode(value, language) {
  return language && lowlight.registered(language) ? lowlight.highlight(language, value) : plain(value);
}
export default highlight;
