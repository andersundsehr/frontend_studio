import MarkdownIt from 'markdown-it';
import TurndownService from 'turndown';

const markdown = new MarkdownIt({ html: false });
const turndown = new TurndownService({ headingStyle: 'atx', bulletListMarker: '*', codeBlockStyle: 'fenced', emDelimiter: '*', strongDelimiter: '**' });
turndown.addRule('listItem', {
  filter: 'li',
  replacement(content, node) {
    const parent = node.parentNode;
    const index = Array.from(parent.children).indexOf(node);
    const prefix = parent.nodeName === 'OL' ? `${Number(parent.getAttribute('start') || 1) + index}. ` : '* ';
    const text = content.replace(/^\n+|\n+$/g, '');
    return prefix + text.replace(/\n/g, '\n' + ' '.repeat(prefix.length)) + '\n';
  },
});
// CKEditor labels unannotated code blocks as plaintext; keep their Markdown fences unannotated.
turndown.addRule('plainCodeBlock', {
  filter: (node) => node.nodeName === 'PRE' && node.firstElementChild?.nodeName === 'CODE'
    && node.firstElementChild.classList.contains('language-plaintext'),
  replacement(_content, node) {
    const code = node.firstElementChild.textContent.replace(/\n$/, '');
    const fence = '`'.repeat(Math.max(3, ...(code.match(/`+/g) || []).map((match) => match.length + 1)));
    return `\n\n${fence}\n${code}\n${fence}\n\n`;
  },
});
turndown.addRule('safeLink', {
  filter: 'a',
  replacement(content, node) {
    const href = node.getAttribute('href') || '';
    if (!markdown.validateLink(href)) return content;
    const title = node.getAttribute('title');
    return `[${content}](${href}${title ? ` "${title.replace(/"/g, '\\"')}"` : ''})`;
  },
});

export function renderMarkdown(source) {
  return markdown.render(source);
}

export function toMarkdown(html) {
  return turndown.turndown(html);
}
