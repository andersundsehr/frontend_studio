import { defaultMarkdownParser, defaultMarkdownSerializer, schema } from 'prosemirror-markdown';
import { EditorState } from 'prosemirror-state';
import { EditorView } from 'prosemirror-view';
import { baseKeymap, setBlockType, toggleMark } from 'prosemirror-commands';
import { keymap } from 'prosemirror-keymap';
import { history, undo, redo } from 'prosemirror-history';
import { wrapInList, splitListItem, sinkListItem, liftListItem } from 'prosemirror-schema-list';

// HTML is text, and markdown-it's URL validation rejects executable schemes.
export function renderMarkdown(source) {
  return defaultMarkdownParser.tokenizer.render(source);
}

export function canEditRichText(source) {
  if (/<\/?[A-Za-z][^>]*>|^\s*\|.*\|\s*$/m.test(source)) return false;
  try {
    return defaultMarkdownSerializer.serialize(defaultMarkdownParser.parse(source)) === source;
  } catch {
    return false;
  }
}

export function createEditor(root, source, onChange) {
  const view = new EditorView(root, {
    state: EditorState.create({
      doc: defaultMarkdownParser.parse(source),
      plugins: [history(), keymap({
        'Mod-z': undo, 'Mod-y': redo, 'Mod-Shift-z': redo,
        Enter: splitListItem(schema.nodes.list_item),
        Tab: sinkListItem(schema.nodes.list_item),
        'Shift-Tab': liftListItem(schema.nodes.list_item),
      }), keymap(baseKeymap)],
    }),
    attributes: { 'aria-label': 'Component documentation rich text', role: 'textbox', 'aria-multiline': 'true' },
    // Reject unsafe links in pasted rich text as well as Markdown input.
    transformPasted(slice) {
      const sanitize = (fragment) => {
        const nodes = [];
        fragment.forEach((node) => {
          if (node.type === schema.nodes.image && !defaultMarkdownParser.tokenizer.validateLink(node.attrs.src)) return;
          const marks = node.marks.filter((mark) => mark.type !== schema.marks.link
            || defaultMarkdownParser.tokenizer.validateLink(mark.attrs.href));
          nodes.push(node.copy(sanitize(node.content)).mark(marks));
        });
        return fragment.constructor.fromArray(nodes);
      };
      return new slice.constructor(sanitize(slice.content), slice.openStart, slice.openEnd);
    },
    dispatchTransaction(transaction) {
      view.updateState(view.state.apply(transaction));
      if (transaction.docChanged) onChange(defaultMarkdownSerializer.serialize(view.state.doc));
    },
  });
  return {
    destroy: () => view.destroy(),
    command(name) {
      const commands = {
        paragraph: setBlockType(schema.nodes.paragraph),
        heading: setBlockType(schema.nodes.heading, { level: 2 }),
        bold: toggleMark(schema.marks.strong), italic: toggleMark(schema.marks.em),
        bullet: wrapInList(schema.nodes.bullet_list), ordered: wrapInList(schema.nodes.ordered_list),
        code: setBlockType(schema.nodes.code_block), undo, redo,
      };
      if (name === 'link') {
        const href = window.prompt('Link URL (https://…, mailto:…, or relative URL)');
        if (href !== null && defaultMarkdownParser.tokenizer.validateLink(href)) {
          toggleMark(schema.marks.link, { href })(view.state, view.dispatch);
        }
      } else commands[name]?.(view.state, view.dispatch);
      view.focus();
    },
  };
}
