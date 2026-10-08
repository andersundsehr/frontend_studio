export { Editor, textblockTypeInputRule } from '@tiptap/core';
export { default as StarterKit } from '@tiptap/starter-kit';
export { default as Image } from '@tiptap/extension-image';
export { TableKit } from '@tiptap/extension-table';
export { Markdown } from '@tiptap/markdown';
export { CodeBlockLowlight } from '@tiptap/extension-code-block-lowlight';
export { closeHistory } from '@tiptap/pm/history';
export { createElement as createIcon } from 'lucide';
import { Bold, Italic, Code, Strikethrough, Link, Check, Unlink, X,
  List, ListOrdered, Quote, SquareCode, Minus, Undo2, Redo2, Image as ImageIcon, CodeXml, Type, ChevronDown } from 'lucide';
export const editorIcons = { Bold, Italic, Code, Strike: Strikethrough, Link, 'Apply link': Check, 'Remove link': Unlink, Cancel: X,
  'Bullet list': List,
  'Numbered list': ListOrdered, Quote, 'Code block': SquareCode, Divider: Minus, Undo: Undo2, Redo: Redo2,
  Image: ImageIcon, Markdown: CodeXml, 'Rich text': Type, Lists: List, Dropdown: ChevronDown };
