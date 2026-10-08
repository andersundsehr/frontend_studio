import { Editor, StarterKit, Image, TableKit, Markdown, CodeBlockLowlight, closeHistory, textblockTypeInputRule, createIcon, editorIcons } from '@andersundsehr/frontend-studio/vendor/inline-documentation-editor';
import { renderMarkdown } from '@andersundsehr/frontend-studio/vendor/markdown-converter';
import { lowlight, codeLanguages, getCodeLanguageLabel } from '@andersundsehr/frontend-studio/vendor/code-highlighting';

let menuId = 0;

function codeBlock(document) {
  return CodeBlockLowlight.extend({
    addInputRules() {
      return [...(this.parent?.() || []), textblockTypeInputRule({ find: /^`{4}$/, type: this.type })];
    },
    renderMarkdown(node) {
      const text = (node.content || []).map(child => child.text || '').join('');
      const longest = Math.max(2, ...(text.match(/`+/g) || []).map(value => value.length));
      const fence = '`'.repeat(longest + 1);
      return `${fence}${node.attrs?.language || ''}\n${text}\n${fence}`;
    },
    addNodeView() {
      return ({ node, editor, getPos }) => {
        let current = node;
        const dom = document.createElement('div');
        dom.className = 'frontend-studio-code-block';
        const header = document.createElement('div');
        header.className = 'frontend-studio-code-header';
        header.contentEditable = 'false';
        const label = document.createElement('span');
        label.className = 'frontend-studio-code-language-label';
        const select = document.createElement('select');
        select.className = 'frontend-studio-code-language-select';
        select.setAttribute('aria-label', 'Code language');
        header.append(label, select);
        const pre = document.createElement('pre');
        const contentDOM = document.createElement('code');
        pre.append(contentDOM);
        dom.append(header, pre);
        const sync = () => {
          const language = current.attrs.language || '';
          label.textContent = getCodeLanguageLabel(language);
          contentDOM.className = language ? `language-${language}` : '';
          select.replaceChildren();
          let selected = false;
          for (const { value, label: name } of codeLanguages) {
            const option = document.createElement('option');
            // Keep imported aliases (js, ts, fluid, …) until the author changes the language.
            option.value = name === getCodeLanguageLabel(language) ? language : value;
            option.textContent = name;
            option.selected = option.value === language;
            selected ||= option.selected;
            select.append(option);
          }
          if (!selected) {
            const option = document.createElement('option');
            option.value = language;
            option.textContent = language;
            option.selected = true;
            select.append(option);
          }
        };
        select.onchange = () => {
          const position = getPos();
          if (typeof position !== 'number') return;
          editor.view.dispatch(closeHistory(editor.state.tr).setNodeMarkup(position, undefined, { ...current.attrs, language: select.value || null }));
          editor.view.focus();
        };
        const selectionChanged = () => {
          const position = getPos();
          const { $from } = editor.state.selection;
          dom.classList.toggle('is-active', editor.isFocused && $from.parent.type.name === 'codeBlock' && $from.before() === position);
        };
        for (const event of ['selectionUpdate', 'focus', 'blur']) editor.on(event, selectionChanged);
        sync();
        return {
          dom, contentDOM,
          update(next) {
            if (next.type !== current.type) return false;
            const changed = next.attrs.language !== current.attrs.language;
            current = next;
            if (changed) sync();
            return true;
          },
          stopEvent: event => header.contains(event.target),
          ignoreMutation: mutation => mutation.type !== 'selection' && !contentDOM.contains(mutation.target),
          destroy: () => {
            select.onchange = null;
            for (const event of ['selectionUpdate', 'focus', 'blur']) editor.off(event, selectionChanged);
          },
        };
      };
    },
  }).configure({ lowlight, enableTabIndentation: true, tabSize: 2, exitOnTripleEnter: false });
}

// Compare rendered content, rather than Markdown formatting, before allowing rich edits.
function renderedContent(markdown) {
  const document = new DOMParser().parseFromString(renderMarkdown(markdown), 'text/html');
  const visit = (node, literal = false) => {
    if (node.nodeType === 3) {
      if (literal) return node.textContent;
      if (!node.textContent.trim() && ['body', 'ul', 'ol', 'table', 'thead', 'tbody', 'tr'].includes(node.parentElement?.localName)) return null;
      return node.textContent.replace(/\s+/g, ' ');
    }
    if (node.nodeType !== 1) return null;
    return [node.localName, Array.from(node.attributes, a => [a.name, a.value]).sort(),
      Array.from(node.childNodes, child => visit(child, literal || node.localName === 'pre')).filter(value => value !== '' && value !== null)];
  };
  return JSON.stringify(visit(document.body));
}

function editorContent(markdown) {
  const document = new DOMParser().parseFromString(renderMarkdown(markdown), 'text/html');
  // Markdown rendering adds a final code-block newline; Tiptap's serializer adds it back.
  for (const code of document.querySelectorAll('pre > code')) code.textContent = code.textContent.replace(/\n$/, '');
  return document.body.innerHTML;
}

export async function createEditor(root, source, onChange, controlsRoot = null) {
  root.replaceChildren();
  root.classList.add('frontend-studio-inline-editor');
  const abort = new AbortController();
  const listen = (element, event, callback, options = {}) => element.addEventListener(event, callback, { ...options, signal: abort.signal });
  const element = (tag, className, parent) => {
    const node = document.createElement(tag);
    node.className = className;
    parent?.append(node);
    return node;
  };
  const warning = element('p', 'frontend-studio-editor-warning', root);
  warning.setAttribute('role', 'status');
  warning.textContent = 'Some Markdown cannot be preserved in rich text. Edit the original Markdown below.';
  warning.hidden = true;
  const content = element('div', '', root);
  const textarea = element('textarea', 'frontend-studio-editor-source', root);
  textarea.setAttribute('aria-label', 'Component documentation Markdown');
  textarea.hidden = true;
  const controls = controlsRoot || element('div', '', root);
  controls.classList.add('frontend-studio-editor-controls');
  if (!controlsRoot) root.prepend(controls);
  const panel = controls.closest('.frontend-studio-documentation');
  const bubble = element('div', 'frontend-studio-editor-bubble', document.body);
  bubble.setAttribute('role', 'toolbar');
  bubble.setAttribute('aria-label', 'Text formatting');
  bubble.hidden = true;
  const button = (parent, label, action) => {
    const node = element('button', '', parent);
    node.type = 'button';
    if (editorIcons[label]) node.append(createIcon(editorIcons[label], { width: 18, height: 18, 'aria-hidden': 'true', focusable: 'false' }));
    node.setAttribute('aria-label', label);
    node.title = label;
    listen(node, 'mousedown', event => event.preventDefault());
    listen(node, 'click', action);
    return node;
  };
  let markdown = source;
  let sourceMode = false;
  let destroyed = false;
  let bubbleDismissed = false;
  const menus = [];
  const closeMenus = () => menus.forEach(menu => menu.close());
  const editor = new Editor({
    element: content,
    content: editorContent(source),
    extensions: [StarterKit.configure({ link: { openOnClick: false, autolink: false }, trailingNode: false, codeBlock: false }), codeBlock(root.ownerDocument),
      Image.configure({ inline: true, allowBase64: false }), TableKit.configure({ table: { resizable: false } }), Markdown],
    editorProps: { attributes: { role: 'textbox', 'aria-multiline': 'true', 'aria-label': 'Component documentation rich text' } },
    onUpdate: ({ editor }) => {
      if (sourceMode) return;
      markdown = editor.getMarkdown();
      onChange(markdown);
      updateBubble();
    },
    onSelectionUpdate: () => { bubbleDismissed = false; updateBubble(); },
    onFocus: () => { bubbleDismissed = false; updateBubble(); },
    onBlur: ({ event }) => {
      if (!bubble.contains(event.relatedTarget)) queueMicrotask(updateBubble);
    },
  });
  const compatible = () => renderedContent(markdown) === renderedContent(editor.getMarkdown());
  const setMode = (mode) => {
    closeMenus();
    sourceMode = mode;
    content.hidden = mode;
    textarea.hidden = !mode;
    textarea.value = markdown;
    sourceButton.replaceChildren(createIcon(editorIcons[mode ? 'Rich text' : 'Markdown'], { width: 18, height: 18, 'aria-hidden': 'true', focusable: 'false' }));
    sourceButton.setAttribute('aria-label', mode ? 'Switch to rich text' : 'Edit Markdown source');
    sourceButton.title = sourceButton.getAttribute('aria-label');
    sourceButton.setAttribute('aria-pressed', String(mode));
    bubble.hidden = true;
  };
  const load = (value) => {
    markdown = value;
    editor.commands.setContent(editorContent(value), { emitUpdate: false });
    warning.hidden = compatible();
    setMode(!warning.hidden);
  };
  const sourceButton = button(controls, 'Markdown', () => {
    if (!sourceMode) {
      setMode(true);
      textarea.focus();
      return;
    }
    editor.commands.setContent(editorContent(markdown), { emitUpdate: false });
    warning.hidden = compatible();
    if (warning.hidden) {
      setMode(false);
      editor.commands.focus();
    }
  });
  listen(textarea, 'input', () => {
    markdown = textarea.value;
    onChange(markdown);
  });
  const run = (command) => {
    editor.chain().focus()[command]().run();
    updateBubble();
  };
  const actions = [];
  const formatRow = element('div', 'frontend-studio-editor-row', bubble);
  const insertRow = element('div', 'frontend-studio-editor-row', bubble);
  const dropdown = (label, icon, options, horizontal = false) => {
    const control = element('div', 'frontend-studio-editor-dropdown', formatRow);
    const trigger = button(control, label, () => menu.hidden ? open() : close(true));
    trigger.replaceChildren();
    const caption = element('span', '', trigger);
    if (icon) caption.append(createIcon(editorIcons[icon], { width: 18, height: 18, 'aria-hidden': 'true', focusable: 'false' }));
    trigger.append(createIcon(editorIcons.Dropdown, { width: 12, height: 12, 'aria-hidden': 'true', focusable: 'false' }));
    trigger.setAttribute('aria-haspopup', 'menu');
    trigger.setAttribute('aria-expanded', 'false');
    const menu = element('div', `frontend-studio-editor-menu${horizontal ? ' is-horizontal' : ''}`, control);
    menu.id = `frontend-studio-editor-menu-${++menuId}`;
    menu.setAttribute('role', 'menu');
    menu.setAttribute('aria-label', label);
    menu.setAttribute('aria-orientation', horizontal ? 'horizontal' : 'vertical');
    trigger.setAttribute('aria-controls', menu.id);
    menu.hidden = true;
    const items = options.map(option => {
      if (option.separator) {
        const separator = element('div', 'frontend-studio-editor-menu-separator', menu);
        separator.setAttribute('role', 'separator');
      }
      const node = button(menu, option.label, () => {
        close();
        option.apply();
        editor.view.focus();
        updateBubble();
      });
      node.setAttribute('role', 'menuitemradio');
      node.tabIndex = -1;
      if (!horizontal) {
        node.replaceChildren(createIcon(editorIcons['Apply link'], { width: 16, height: 16, 'aria-hidden': 'true', focusable: 'false' }));
        const text = element('span', 'frontend-studio-editor-style-name', node);
        text.textContent = option.label;
        if (option.level) text.classList.add(`is-heading-${option.level}`);
        const hint = element('span', 'frontend-studio-editor-style-hint', node);
        hint.textContent = option.level ? `(H${option.level})` : '';
        hint.setAttribute('aria-hidden', 'true');
      }
      return { ...option, node };
    });
    const close = (focus = false) => {
      menu.hidden = true;
      trigger.setAttribute('aria-expanded', 'false');
      if (focus) trigger.focus();
    };
    const open = (last = false) => {
      closeMenus();
      menu.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      updateBubble();
      const enabled = items.filter(item => !item.node.disabled);
      (enabled.find(item => item.active()) || (last ? enabled.at(-1) : enabled[0]))?.node.focus();
    };
    listen(trigger, 'keydown', event => {
      if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;
      event.preventDefault();
      open(event.key === 'ArrowUp');
    });
    listen(menu, 'keydown', event => {
      if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        close(true);
      } else if (event.key === 'Tab') {
        close(true);
      } else if (['ArrowDown', 'ArrowUp', 'Home', 'End', ...(horizontal ? ['ArrowLeft', 'ArrowRight'] : [])].includes(event.key)) {
        event.preventDefault();
        const enabled = items.filter(item => !item.node.disabled);
        const index = enabled.findIndex(item => item.node === document.activeElement);
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? enabled.length - 1 :
          (index + (['ArrowUp', 'ArrowLeft'].includes(event.key) ? -1 : 1) + enabled.length) % enabled.length;
        enabled[next]?.node.focus();
      }
    });
    const sync = () => {
      for (const item of items) {
        item.node.setAttribute('aria-checked', String(item.active()));
        item.node.disabled = !item.active() && !item.enabled();
      }
      trigger.disabled = items.every(item => item.node.disabled);
      if (icon) trigger.setAttribute('aria-pressed', String(items.some(item => item.active())));
      else caption.textContent = items.find(item => item.active())?.label || 'Text style';
      if (menu.hidden) return;
      const bounds = trigger.getBoundingClientRect();
      // Keep both toolbar rows accessible while a menu is open.
      const toolbarBounds = bubble.getBoundingClientRect();
      const left = Math.max(8, Math.min(bounds.left, window.innerWidth - menu.offsetWidth - 8));
      const below = toolbarBounds.bottom + 6;
      const top = below + menu.offsetHeight <= window.innerHeight - 8 ? below : toolbarBounds.top - menu.offsetHeight - 6;
      menu.style.left = `${left}px`;
      menu.style.top = `${Math.max(8, top)}px`;
    };
    menus.push({ control, menu, close, sync });
  };
  dropdown('Text style', null, ['Normal', 'Title', 'Subtitle', 'Heading 1', 'Heading 2', 'Heading 3', 'Heading 4'].map((label, level) => ({
    label, level, separator: level === 1 || level === 5,
    active: () => level ? editor.isActive('heading', { level }) : editor.isActive('paragraph'),
    enabled: () => level ? editor.can().setHeading({ level }) : editor.can().setParagraph(),
    apply: () => level ? editor.commands.setHeading({ level }) : editor.commands.setParagraph(),
  })));
  for (const [label, mark, command] of [['Bold', 'bold', 'toggleBold'], ['Italic', 'italic', 'toggleItalic'], ['Strike', 'strike', 'toggleStrike']]) {
    const node = button(formatRow, label, () => run(command));
    actions.push({ node, active: () => editor.isActive(mark), enabled: () => editor.can()[command]() });
  }
  const codeActions = element('div', 'frontend-studio-editor-code-actions', formatRow);
  for (const [label, type, command] of [['Code', 'code', 'toggleCode'], ['Code block', 'codeBlock', 'toggleCodeBlock']]) {
    const node = button(codeActions, label, () => run(command));
    actions.push({ node, active: () => editor.isActive(type), enabled: () => editor.can()[command]() });
  }
  dropdown('Lists', 'Lists', [['Bullet list', 'toggleBulletList', 'bulletList'], ['Numbered list', 'toggleOrderedList', 'orderedList']].map(([label, command, type]) => ({
    label,
    active: () => editor.isActive(type),
    enabled: () => editor.can()[command](),
    apply: () => editor.commands[command](),
  })), true);
  for (const [label, command, type] of [['Quote', 'toggleBlockquote', 'blockquote'], ['Divider', 'setHorizontalRule']]) {
    const node = button(formatRow, label, () => run(command));
    actions.push({ node, active: type ? () => editor.isActive(type) : null, enabled: () => editor.can()[command]() });
  }
  const linkForm = element('form', 'frontend-studio-editor-link', bubble);
  linkForm.hidden = true;
  const urlInput = element('input', '', linkForm);
  urlInput.type = 'text';
  urlInput.setAttribute('aria-label', 'Link URL');
  listen(urlInput, 'input', () => urlInput.setCustomValidity(''));
  const apply = button(linkForm, 'Apply link', () => linkForm.requestSubmit());
  apply.type = 'button';
  button(linkForm, 'Remove link', () => { editor.chain().focus().unsetLink().run(); linkForm.hidden = true; });
  button(linkForm, 'Cancel', () => { linkForm.hidden = true; editor.commands.focus(); });
  const linkButton = button(insertRow, 'Link', () => {
    closeMenus();
    linkForm.hidden = false;
    urlInput.value = editor.getAttributes('link').href || '';
    urlInput.focus();
  });
  listen(linkForm, 'submit', event => {
    event.preventDefault();
    const href = urlInput.value.trim();
    let allowed = false;
    try { allowed = ['http:', 'https:', 'mailto:', 'tel:'].includes(new URL(href, window.location.href).protocol); } catch { /* Keep the form open for correction. */ }
    urlInput.setCustomValidity(allowed ? '' : 'Enter an HTTP, HTTPS, mailto or tel link.');
    if (!allowed) { urlInput.reportValidity(); return; }
    editor.chain().focus().extendMarkRange('link').setLink({ href }).run();
    linkForm.hidden = true;
  });
  actions.push({ node: linkButton, active: () => editor.isActive('link'), enabled: () => editor.can().setLink({ href: 'https://example.org' }) });
  const imageButton = button(insertRow, 'Image', () => {
    const src = window.prompt('Image URL');
    if (!src) return;
    try {
      if (!['http:', 'https:'].includes(new URL(src, window.location.href).protocol)) return;
      editor.chain().focus().setImage({ src, alt: window.prompt('Image description') || '' }).run();
    } catch { /* Invalid image URLs are not inserted. */ }
    updateBubble();
  });
  actions.push({ node: imageButton, enabled: () => editor.can().setImage({ src: 'https://example.org/image.png' }) });
  for (const command of ['undo', 'redo']) {
    const node = button(insertRow, command === 'undo' ? 'Undo' : 'Redo', () => run(command));
    actions.push({ node, enabled: () => editor.can()[command]() });
  }
  function updateBubble() {
    if (destroyed) return;
    panel?.classList.toggle('has-editor-focus', !sourceMode && (editor.isFocused || bubble.contains(document.activeElement)));
    const visible = !bubbleDismissed && !sourceMode && (editor.isFocused || bubble.contains(document.activeElement));
    bubble.hidden = !visible;
    if (!visible) { linkForm.hidden = true; closeMenus(); return; }
    for (const { node, active, enabled } of actions) {
      if (active) node.setAttribute('aria-pressed', String(active()));
      node.disabled = !enabled();
    }
    const start = editor.view.coordsAtPos(editor.state.selection.from);
    bubble.style.left = `${Math.max(8, Math.min(start.left, window.innerWidth - bubble.offsetWidth - 8))}px`;
    const gap = 16;
    const top = start.top - bubble.offsetHeight - gap;
    const actionBounds = panel?.querySelector('[data-doc-actions]')?.getBoundingClientRect();
    const coversActions = actionBounds && top < actionBounds.bottom && top + bubble.offsetHeight > actionBounds.top;
    bubble.style.top = `${top >= 8 && !coversActions ? top : start.bottom + gap}px`;
    menus.forEach(menu => menu.sync());
  }
  listen(document, 'scroll', updateBubble, { capture: true });
  listen(window, 'resize', updateBubble);
  listen(bubble, 'focusin', updateBubble);
  listen(bubble, 'focusout', event => {
    for (const menu of menus) if (!menu.control.contains(event.relatedTarget)) menu.close();
    if (!bubble.contains(event.relatedTarget)) queueMicrotask(updateBubble);
  });
  listen(document, 'pointerdown', event => {
    for (const menu of menus) if (!menu.control.contains(event.target)) menu.close();
    if (!bubble.contains(event.target) && !content.contains(event.target) && !controls.contains(event.target)) {
      bubble.hidden = true;
    }
  });
  const dismiss = event => {
    if (event.key !== 'Escape') return;
    if (bubble.contains(document.activeElement)) editor.view.focus();
    bubbleDismissed = true;
    bubble.hidden = true;
    linkForm.hidden = true;
    closeMenus();
  };
  listen(root, 'keydown', dismiss);
  listen(bubble, 'keydown', dismiss);
  load(source);
  return {
    setData: load,
    getData: () => markdown,
    destroy: () => {
      destroyed = true;
      abort.abort();
      bubble.remove();
      sourceButton.remove();
      panel?.classList.remove('has-editor-focus');
      if (controlsRoot) controls.classList.remove('frontend-studio-editor-controls');
      editor.destroy();
      root.classList.remove('frontend-studio-inline-editor');
    },
  };
}
