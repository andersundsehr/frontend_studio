import { ClassicEditor } from '@ckeditor/ckeditor5-editor-classic';
import { Essentials } from '@ckeditor/ckeditor5-essentials';
import { Paragraph } from '@ckeditor/ckeditor5-paragraph';
import { Heading } from '@ckeditor/ckeditor5-heading';
import { Bold, Italic } from '@ckeditor/ckeditor5-basic-styles';
import { List } from '@ckeditor/ckeditor5-list';
import { Link } from '@ckeditor/ckeditor5-link';
import { CodeBlock } from '@ckeditor/ckeditor5-code-block';
import { renderMarkdown, toMarkdown } from '@andersundsehr/frontend-studio/backend/markdown-editor.bundle.js';

export async function createEditor(root, source, onChange) {
  const element = document.createElement('div');
  root.replaceChildren(element);
  const editor = await ClassicEditor.create(element, {
    licenseKey: 'GPL',
    initialData: renderMarkdown(source),
    plugins: [Essentials, Paragraph, Heading, Bold, Italic, List, Link, CodeBlock],
    toolbar: ['heading', '|', 'bold', 'italic', 'link', '|', 'bulletedList', 'numberedList', 'codeBlock', '|', 'undo', 'redo'],
    heading: { options: [
      { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
      ...[1, 2, 3, 4, 5, 6].map((level) => ({ model: `heading${level}`, view: `h${level}`, title: `Heading ${level}`, class: `ck-heading_heading${level}` })),
    ] },
    link: { allowedProtocols: ['http', 'https', 'mailto', 'tel'] },
  });
  // CKEditor may normalize more than the Markdown converter. Never silently rewrite a document.
  if (toMarkdown(editor.getData()) !== source) {
    await editor.destroy();
    throw new Error('Keep editing in Markdown source: CKEditor would change the existing Markdown formatting or unsupported syntax.');
  }
  editor.ui.getEditableElement().setAttribute('aria-label', 'Component documentation rich text');
  editor.model.document.on('change:data', () => onChange(toMarkdown(editor.getData())));
  return editor;
}
