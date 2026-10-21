import { renderMarkdown as convertMarkdown } from '@andersundsehr/frontend-studio/vendor/markdown-converter';
import { highlightCode, getCodeLanguageLabel } from '@andersundsehr/frontend-studio/vendor/code-highlighting';

export function renderMarkdown(markdown) {
  const document = new DOMParser().parseFromString(convertMarkdown(markdown), 'text/html');
  const toDOM = node => {
    if (node.type === 'text') return document.createTextNode(node.value);
    const span = document.createElement('span');
    span.className = (node.properties?.className || []).join(' ');
    span.append(...node.children.map(toDOM));
    return span;
  };
  for (const code of document.querySelectorAll('pre > code')) {
    const language = Array.from(code.classList).find(value => value.startsWith('language-'))?.slice(9) || '';
    code.replaceChildren(...highlightCode(code.textContent, language).children.map(toDOM));
    const block = document.createElement('div');
    block.className = 'frontend-studio-code-block';
    const header = document.createElement('div');
    header.className = 'frontend-studio-code-header';
    const label = document.createElement('span');
    label.className = 'frontend-studio-code-language-label';
    label.textContent = getCodeLanguageLabel(language);
    header.append(label);
    const pre = code.parentElement;
    pre.replaceWith(block);
    block.append(header, pre);
  }
  return document.body.innerHTML;
}
