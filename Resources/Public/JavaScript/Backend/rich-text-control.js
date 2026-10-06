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
  const getData = () => {
    const template = document.createElement('template');
    template.innerHTML = editor.getData({ skipListItemIds: true });
    template.content.querySelectorAll('li.ck-list-marker-bold, li.ck-list-marker-italic').forEach((item) => {
      item.classList.remove('ck-list-marker-bold', 'ck-list-marker-italic');
      if (item.classList.length === 0) {
        item.removeAttribute('class');
      }
    });
    return template.innerHTML;
  };
  const editable = editor.ui.getEditableElement();
  editable.setAttribute('aria-label', field.getAttribute('aria-label') || field.name);
  editor.model.document.on('change:data', () => {
    field.value = getData();
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
    getValue: getData,
    setValue: (value) => { editor.setData(value ?? ''); field.value = getData(); },
    validate: () => required && getData().trim() === '' ? 'Enter rich text.' : '',
    destroy: async () => { await editor.destroy(); field.required = required; },
  };
}
