// Match HtmlSourceHighlighter's Fluid tokens while keeping source text as safe HAST nodes.
const prefix = 'frontend-studio-variant-html-source__';
const attributePattern = /(?<attribute>[^\t\n\v\f\r "'<>/=]+)(?<equals>[\t\n\v\f\r ]*=[\t\n\v\f\r ]*)?(?<value>"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|[^\t\n\v\f\r "'=<>`]+)?/gs;
const tokenPattern = /(?<string>"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*')|(?<tag>[\p{L}\p{N}\p{M}\p{Pc}.]+:[\p{L}\p{N}\p{M}\p{Pc}.]+(?=[\p{Z}\t\n\v\f\r\x85]*\())|(?<attribute>[\p{L}\p{N}\p{M}\p{Pc}-]+(?=[\p{Z}\t\n\v\f\r\x85]*[:=]))|-?\p{Nd}+(?:\.\p{Nd}+)?|[\p{L}\p{N}\p{M}\p{Pc}.]+(?:-[\p{L}\p{N}\p{M}\p{Pc}.]+)*|(?<punctuation>->|[{}(),:=|+*/%<>!?&^\[\]\-])|(?<text>[\p{Z}\t\n\v\f\r\x85]+)|./gsu;

function expressionScanner(source) {
  const ends = new Map();
  const quotes = new Map();
  return (start) => {
    if (ends.has(start)) return ends.get(start);
    const stack = [start];
    for (let offset = start + 1; offset < source.length; offset++) {
      const character = source[offset];
      if (character === '"' || character === "'") {
        if (!quotes.has(offset)) {
          let end = offset + 1;
          while (end < source.length && source[end] !== character) {
            if (source[end] === '\\') end++;
            end++;
          }
          quotes.set(offset, end < source.length ? end : null);
        }
        const end = quotes.get(offset);
        if (end === null) break;
        offset = end;
      } else if (character === '{') {
        if (ends.has(offset)) {
          const end = ends.get(offset);
          if (end === null) break;
          offset = end - 1;
        } else stack.push(offset);
      } else if (character === '}') {
        ends.set(stack.pop(), offset + 1);
        if (stack.length === 0) return offset + 1;
      }
    }
    // Cache unfinished nested expressions too, so typing a long incomplete block stays linear.
    stack.forEach(offset => ends.set(offset, null));
    return null;
  };
}

function nextExpression(source, offset, endAt, limit = source.length) {
  for (let start = source.indexOf('{', offset); start !== -1 && start < limit; start = source.indexOf('{', start + 1)) {
    const end = endAt(start);
    if (end !== null) return { start, end };
  }
  return null;
}

export function highlightFluid(source) {
  const children = [];
  const emit = (token, value) => {
    for (const part of value.split(/(\r?\n)/)) {
      if (!part) continue;
      children.push(part === '\n' || part === '\r\n'
        ? { type: 'text', value: part }
        : { type: 'element', tagName: 'span', properties: { className: [prefix + token] }, children: [{ type: 'text', value: part }] });
    }
  };
  const expression = (value) => {
    for (const match of value.matchAll(tokenPattern)) {
      const token = Object.keys(match.groups).find(key => match.groups[key]) || 'fluid-expression';
      emit(token, match[0]);
    }
  };
  const text = (value, token = 'text') => {
    const endAt = expressionScanner(value);
    let offset = 0;
    let next;
    while ((next = nextExpression(value, offset, endAt))) {
      emit(token, value.slice(offset, next.start));
      expression(value.slice(next.start, next.end));
      offset = next.end;
    }
    emit(token, value.slice(offset));
  };
  const attributes = (value) => {
    const endAt = expressionScanner(value);
    let offset = 0;
    while (offset < value.length) {
      attributePattern.lastIndex = offset;
      const match = attributePattern.exec(value);
      const next = nextExpression(value, offset, endAt, match ? match.index + 1 : value.length);
      if (next) {
        emit('text', value.slice(offset, next.start));
        expression(value.slice(next.start, next.end));
        offset = next.end;
      } else if (match) {
        emit('text', value.slice(offset, match.index));
        emit('attribute', match.groups.attribute);
        if (match.groups.equals) emit('punctuation', match.groups.equals);
        if (match.groups.value) text(match.groups.value, 'string');
        offset = match.index + match[0].length;
      } else {
        emit('text', value.slice(offset));
        break;
      }
    }
  };
  const endAt = expressionScanner(source);
  let offset = 0;
  while (offset < source.length) {
    const start = source.indexOf('<', offset);
    const next = nextExpression(source, offset, endAt, start === -1 ? source.length : start);
    if (next) {
      emit('text', source.slice(offset, next.start));
      expression(source.slice(next.start, next.end));
      offset = next.end;
      continue;
    }
    if (start === -1) { text(source.slice(offset)); break; }
    text(source.slice(offset, start));
    if (source.startsWith('<!--', start)) {
      const closing = source.indexOf('-->', start + 4);
      const end = closing === -1 ? source.length : closing + 3;
      emit('comment', source.slice(start, end));
      offset = end;
      continue;
    }
    let quote = null;
    let end = start + 1;
    for (; end < source.length; end++) {
      const character = source[end];
      if (quote !== null) {
        if (character === '\\') end++;
        else if (character === quote) quote = null;
      } else if (character === '{' && endAt(end) !== null) end = endAt(end) - 1;
      else if (character === '"' || character === "'") quote = character;
      else if (character === '>') break;
    }
    if (end >= source.length) { text(source.slice(start)); break; }
    const tag = source.slice(start, end + 1);
    const match = /^(<\/?)([^\t\n\v\f\r >\/]+)(.*?)(\/?>)$/s.exec(tag);
    if (/^<![^>]*>$/s.test(tag)) emit('doctype', tag);
    else if (match) {
      emit('punctuation', match[1]);
      emit('tag', match[2]);
      attributes(match[3]);
      emit('punctuation', match[4]);
    } else emit('text', tag);
    offset = end + 1;
  }
  return { type: 'root', children, data: { language: 'fluid', relevance: 0 } };
}
