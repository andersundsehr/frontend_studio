import Modal from '@typo3/backend/modal.js';

export function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character]));
}

export function failureSummary(result) {
  switch (result.status) {
    case 'failed':
      return { title: 'Snapshot mismatch', next: 'Review the changed words and lines. If the change is intentional, update the snapshot with the CLI, review it, then rerun.' };
    case 'missing':
      return { title: 'Missing snapshot', next: result.message?.startsWith('Created baseline.')
        ? 'Review and commit the new snapshot, then rerun the test.'
        : 'Create and review the snapshot outside Production, commit it, then rerun the test.' };
    default:
      return { title: 'Test error', next: 'Fix the reported error, then rerun the test. The error details below may help identify the cause.' };
  }
}

export function focusedRows(rows, context = 2) {
  const visible = new Set();
  rows.forEach((row, index) => {
    if (row.changed) {
      for (let neighbor = Math.max(0, index - context); neighbor <= Math.min(rows.length - 1, index + context); neighbor++) visible.add(neighbor);
    }
  });
  const output = [];
  let previous = -1;
  for (const index of [...visible].sort((a, b) => a - b)) {
    if (index > previous + 1) output.push(null);
    output.push(rows[index]);
    previous = index;
  }
  if (previous < rows.length - 1) output.push(null);
  return output;
}

function renderSegments(segments, side) {
  return segments.map((segment) => segment.changed
    ? `<mark class="fs-snapshot-${side}">${escapeHtml(segment.text)}</mark>`
    : escapeHtml(segment.text)).join('');
}

export function renderDiffRows(rows) {
  return rows.map((row) => {
    if (row === null) return '<tr><td colspan="4" class="text-muted">… unchanged lines …</td></tr>';
    if (!row.changed) return `<tr><td></td><td>${escapeHtml(row.expected ?? '—')}</td><td>${escapeHtml(row.actual ?? '—')}</td><td><code>${renderSegments(row.expectedSegments, 'removed')}</code></td></tr>`;
    return (row.expected === null ? '' : `<tr><td class="text-danger">−</td><td>${escapeHtml(row.expected)}</td><td>—</td><td><code>${renderSegments(row.expectedSegments, 'removed')}</code></td></tr>`)
      + (row.actual === null ? '' : `<tr><td class="text-success">+</td><td>—</td><td>${escapeHtml(row.actual)}</td><td><code>${renderSegments(row.actualSegments, 'added')}</code></td></tr>`);
  }).join('');
}

export function renderSnapshotDiff(results) {
  return results.map((result) => {
    const summary = failureSummary(result);
    const rows = Array.isArray(result.diff) ? result.diff : [];
    const diff = result.status === 'failed' && rows.length > 0
      ? `<p class="text-muted">− snapshot · + current output · highlighted words changed. Dynamic values are masked using the snapshot rules.</p>
        <div class="fs-snapshot-diff" tabindex="0" role="region" aria-label="Snapshot changes"><table><colgroup><col style="width:1.5rem"><col style="width:5rem"><col style="width:4.5rem"><col></colgroup><thead><tr><th aria-label="Change"></th><th>Snapshot</th><th>Actual</th><th>HTML</th></tr></thead><tbody>${renderDiffRows(focusedRows(rows))}</tbody></table></div>`
      : '';
    const fullOutput = result.expected || result.actual
      ? `<details class="mt-3"><summary>Show full output</summary><h4 class="mt-3">Snapshot</h4><pre>${escapeHtml(result.expected)}</pre><h4>Current output</h4><pre>${escapeHtml(result.actual)}</pre></details>` : '';
    const technical = result.path || result.trace
      ? `<div class="mt-3">${result.path ? `<p class="mt-2">Snapshot file: <code class="text-break">${escapeHtml(result.path)}</code></p>` : ''}${result.trace ? `<h4>Stack trace</h4><pre>${escapeHtml(result.trace)}</pre>` : ''}</div>` : '';
    return `<h3>${escapeHtml(summary.title)}</h3><p class="text-break"><strong>${escapeHtml(result.identifier)}</strong></p><p class="text-break">${escapeHtml(result.message)}</p><p>${escapeHtml(summary.next)}</p>${diff}${fullOutput}${technical}`;
  }).join('<hr>');
}

export function copyDetails(result, context = {}) {
  return [failureSummary(result).title, result.identifier, result.message,
    context.site ? `Site: ${context.site} · Language: ${context.language}` : '',
    result.path ? `Snapshot: ${result.path}` : '', result.trace || '',
    result.expected ? `Snapshot output:\n${result.expected}` : '',
    result.actual ? `Current output:\n${result.actual}` : ''].filter(Boolean).join('\n\n');
}

export async function showSnapshotDiff(results, options = {}) {
  if (results.length === 0) return;
  const [{ html }, { unsafeHTML }] = await Promise.all([import('lit'), import('lit/directives/unsafe-html.js')]);
  const trigger = document.activeElement;
  let selected = 0;
  let feedback = '';
  let modal;
  const content = () => html`
    <style>
      .fs-snapshot-view {display:grid;grid-template-columns:minmax(0,1fr);gap:1rem}
      .fs-snapshot-view.fs-snapshot-multiple {grid-template-columns:minmax(9rem,25%) minmax(0,1fr)}
      .fs-snapshot-view nav {display:flex;flex-direction:column;gap:.5rem;max-height:60vh;overflow:auto}
      .fs-snapshot-view nav button {text-align:left;white-space:normal;overflow-wrap:anywhere}
      .fs-snapshot-view nav button[aria-current="true"] {outline:2px solid var(--typo3-component-border-color);outline-offset:-2px;font-weight:bold}
      .fs-snapshot-view section {min-width:0}
      .fs-snapshot-view pre {white-space:pre-wrap;overflow-wrap:anywhere;max-height:45vh;overflow:auto}
      .fs-snapshot-diff {max-height:45vh;overflow:auto;border:1px solid var(--typo3-component-border-color)}
      .fs-snapshot-diff table {width:100%;font-family:monospace;font-size:.85rem;border-collapse:collapse;table-layout:fixed}
      .fs-snapshot-diff th {position:sticky;top:0;background:var(--typo3-surface-container-lowest,#fff)}
      .fs-snapshot-diff th,.fs-snapshot-diff td {padding:.25rem .5rem;vertical-align:top}
      .fs-snapshot-diff td:last-child {white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word}
      .fs-snapshot-diff td code {color:inherit}
      .fs-snapshot-removed {background:var(--typo3-surface-container-lowest,#fff);color:var(--typo3-text-color-danger,#b00020);text-decoration:line-through}
      .fs-snapshot-added {background:var(--typo3-surface-container-lowest,#fff);color:var(--typo3-text-color-success,#006b35);text-decoration:underline}
      @media (max-width:600px) {
        .fs-snapshot-view.fs-snapshot-multiple {grid-template-columns:minmax(0,1fr)}
        .fs-snapshot-view nav {max-height:10rem}
      }
    </style>
    <div class="fs-snapshot-view ${results.length > 1 ? 'fs-snapshot-multiple' : ''}">
      ${results.length > 1 ? html`<nav aria-label="Failed variants">${results.map((result, index) => html`
        <button type="button" class="btn btn-default" aria-current=${index === selected ? 'true' : 'false'}
          @click=${() => { selected = index; feedback = ''; update(); }}>${result.identifier}<br><small>${failureSummary(result).title}</small></button>
      `)}</nav>` : ''}
      <section aria-label="Failure details">
        <p class="text-muted" role="status" aria-live="polite">Failure ${selected + 1} of ${results.length}${options.context?.site ? ` · ${options.context.site} / ${options.context.language}` : ''}</p>
        ${unsafeHTML(renderSnapshotDiff([results[selected]]))}
        <div class="d-flex gap-2 flex-wrap mt-3">
          ${options.openComponent && options.variantIdentifiers?.includes(results[selected].identifier) ? html`
            <button type="button" class="btn btn-default" @click=${() => { const identifier = results[selected].identifier; modal.hideModal(); options.openComponent(identifier, options.context); }}>Open component</button>` : ''}
          <button type="button" class="btn btn-default" @click=${async () => {
            const index = selected;
            try {
              await navigator.clipboard.writeText(copyDetails(results[index], options.context));
              if (selected === index) feedback = 'Details copied.';
            } catch {
              if (selected === index) feedback = 'Could not copy details. Select and copy the output manually.';
            }
            update();
          }}>Copy details</button>
          <span role="status" aria-live="polite">${feedback}</span>
        </div>
      </section>
    </div>`;
  const update = () => {
    modal.modalTitle = `${failureSummary(results[selected]).title}: ${results[selected].identifier}`;
    modal.templateResultContent = content();
  };
  modal = Modal.advanced({
    title: `${failureSummary(results[0]).title}: ${results[0].identifier}`,
    content: content(),
    size: Modal.sizes.large,
    buttons: [{ text: 'Close', active: true, btnClass: 'btn-default', trigger: () => modal.hideModal() }],
  });
  modal.addEventListener('typo3-modal-hidden', () => trigger?.focus(), { once: true });
}
