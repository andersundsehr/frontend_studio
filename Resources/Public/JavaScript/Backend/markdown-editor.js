import { Plugin } from '@ckeditor/ckeditor5-core';
import { SourceEditing } from '@ckeditor/ckeditor5-source-editing';
import { ClassicEditor } from '@ckeditor/ckeditor5-editor-classic';
import { Essentials } from '@ckeditor/ckeditor5-essentials';
import { Paragraph } from '@ckeditor/ckeditor5-paragraph';
import { Heading } from '@ckeditor/ckeditor5-heading';
import { Bold, Italic } from '@ckeditor/ckeditor5-basic-styles';
import { List } from '@ckeditor/ckeditor5-list';
import { Link } from '@ckeditor/ckeditor5-link';
import { CodeBlock } from '@ckeditor/ckeditor5-code-block';
import { renderMarkdown, toMarkdown } from '@andersundsehr/frontend-studio/backend/markdown-editor.bundle.js';

// Keep CKEditor's source view in Markdown while its model continues to use HTML.
class MarkdownData extends Plugin {
  init() {
    const html = this.editor.data.processor;
    this.editor.data.processor = {
      toView: (markdown) => html.toView(renderMarkdown(markdown)),
      toData: (view) => toMarkdown(html.toData(view)),
    };
  }
}

class MarkdownSourceEditing extends SourceEditing {
  init() {
    super.init();
    this.editor.ui.componentFactory.add('markdownSource', () => {
      const button = this.editor.ui.componentFactory.create('sourceEditing');
      button.label = 'Markdown';
      return button;
    });
  }
}

export async function createEditor(root, source, onChange) {
  const element = document.createElement('div');
  root.replaceChildren(element);
  const editor = await ClassicEditor.create(element, {
    licenseKey: 'GPL',
    initialData: source,
    plugins: [MarkdownData, MarkdownSourceEditing, Essentials, Paragraph, Heading, Bold, Italic, List, Link, CodeBlock],
    toolbar: ['markdownSource', '|', 'heading', '|', 'bold', 'italic', 'link', '|', 'bulletedList', 'numberedList', 'codeBlock', '|', 'undo', 'redo'],
    heading: { options: [
      { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
      ...[1, 2, 3, 4, 5, 6].map((level) => ({ model: `heading${level}`, view: `h${level}`, title: `Heading ${level}`, class: `ck-heading_heading${level}` })),
    ] },
    link: { allowedProtocols: ['http', 'https', 'mailto', 'tel'] },
  });
  editor.ui.getEditableElement().setAttribute('aria-label', 'Component documentation rich text');
  const sourceEditing = editor.plugins.get(MarkdownSourceEditing);
  let markdown = source;
  let switching = false;
  sourceEditing.on('change:isSourceEditingMode', () => { switching = true; }, { priority: 'highest' });
  sourceEditing.on('change:isSourceEditingMode', () => {
    if (sourceEditing.isSourceEditingMode) {
      const textarea = root.querySelector('.ck-source-editing-area textarea');
      textarea.setAttribute('aria-label', 'Component documentation Markdown');
      // Preserve the exact source, including formatting and its final newline.
      textarea.value = markdown;
      textarea.parentElement.dataset.value = markdown;
      textarea.addEventListener('input', () => {
        markdown = textarea.value;
        onChange(markdown);
      });
    }
    switching = false;
  }, { priority: 'lowest' });
  editor.model.document.on('change:data', () => {
    if (switching || sourceEditing.isSourceEditingMode) return;
    markdown = editor.getData();
    onChange(markdown);
  });
  const setData = editor.setData.bind(editor);
  editor.setData = (value) => {
    sourceEditing.isSourceEditingMode = false;
    setData(value);
    markdown = value;
  };
  return editor;
}
