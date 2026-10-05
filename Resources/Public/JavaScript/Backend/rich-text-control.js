import { ClassicEditor } from '@ckeditor/ckeditor5-editor-classic';
import { Essentials } from '@ckeditor/ckeditor5-essentials';
import { Paragraph } from '@ckeditor/ckeditor5-paragraph';
import { Bold, Italic } from '@ckeditor/ckeditor5-basic-styles';
import { Link } from '@ckeditor/ckeditor5-link';
import { List } from '@ckeditor/ckeditor5-list';

export default async function mount({ field, changed, signal }) {
  const editor = await ClassicEditor.create(field, {
    licenseKey: 'GPL',
    plugins: [Essentials, Paragraph, Bold, Italic, Link, List],
    toolbar: ['undo', 'redo', '|', 'bold', 'italic', 'link', 'bulletedList', 'numberedList'],
    initialData: field.value,
  });
  const editable = editor.ui.getEditableElement();
  editable.setAttribute('aria-label', field.getAttribute('aria-label') || field.name);
  editor.model.document.on('change:data', () => {
    field.value = editor.getData();
    changed();
  });
  field.addEventListener('invalid', (event) => {
    event.preventDefault();
    editor.editing.view.focus();
  }, { signal });
  // Native validation needs a focusable target even though CKEditor hides its source textarea.
  const required = field.required;
  field.required = false;
  return {
    getValue: () => editor.getData(),
    setValue: (value) => { editor.setData(value ?? ''); field.value = editor.getData(); },
    validate: () => required && editor.getData().trim() === '' ? 'Enter rich text.' : '',
    destroy: async () => { await editor.destroy(); field.required = required; },
  };
}
