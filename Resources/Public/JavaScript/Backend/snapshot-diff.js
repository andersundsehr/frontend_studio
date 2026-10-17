import Modal from '@typo3/backend/modal.js';

export function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character]));
}

export function failureSummary(result) {
  switch (result.status) {
    case 'failed':
      return { title: 'Snapshot mismatch', tone: 'danger', icon: '✕', next: 'Review the diff. For intentional changes, use Update snapshot, review and commit the file, then rerun the test.' };
    case 'missing':
      return { title: 'Missing snapshot', tone: 'warning', icon: '!', next: 'Use Create snapshot outside Production, review and commit the file, then rerun the test.' };
    case 'created':
      return { title: 'Snapshot created', tone: 'warning', icon: '!', next: 'Review and commit the new snapshot, then rerun the test. Creating a snapshot does not count as a passed test.' };
    case 'updated':
      return { title: 'Snapshot updated', tone: 'warning', icon: '!', next: 'Review and commit the updated snapshot, then rerun the test. An update does not count as a passed test.' };
    case 'passed':
      return { title: 'Snapshot passed', tone: 'success', icon: '✓', next: 'The current output matches the snapshot. No file changes were needed.' };
    default:
      return { title: 'Test error', tone: 'danger', icon: '✕', next: 'Fix the reported error, then rerun the test. The error details below may help identify the cause.' };
  }
}

export function focusedRows(rows, context = 2) {
  const visible = new Set();
  rows.forEach((row, index) => {
    if (row.changed) {
      visible.add(index);
      for (const direction of [-1, 1]) {
        const expected = new Set();
        const actual = new Set();
        for (let neighbor = index + direction; neighbor >= 0 && neighbor < rows.length; neighbor += direction) {
          if (expected.size >= context && actual.size >= context) break;
          visible.add(neighbor);
          const surrounding = rows[neighbor];
          if (!surrounding.changed) {
            if (surrounding.expected !== null && surrounding.expected !== row.expected) expected.add(surrounding.expected);
            if (surrounding.actual !== null && surrounding.actual !== row.actual) actual.add(surrounding.actual);
          }
        }
      }
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
    return (row.expected === null ? '' : `<tr class="fs-snapshot-line-removed"><td class="text-danger">−</td><td>${escapeHtml(row.expected)}</td><td>—</td><td><code>${renderSegments(row.expectedSegments, 'removed')}</code></td></tr>`)
      + (row.actual === null ? '' : `<tr class="fs-snapshot-line-added"><td class="text-success">+</td><td>—</td><td>${escapeHtml(row.actual)}</td><td><code>${renderSegments(row.actualSegments, 'added')}</code></td></tr>`);
  }).join('');
}

export function renderSnapshotSummary(result) {
  const summary = failureSummary(result);
  return `<header class="fs-snapshot-summary fs-snapshot-tone-${summary.tone}"><span class="fs-snapshot-state text-${summary.tone}" aria-hidden="true">${summary.icon}</span><div><h3>${escapeHtml(summary.title)}</h3><dl><dt>Variant</dt><dd><code>${escapeHtml(result.identifier)}</code></dd></dl>${result.message ? `<p class="text-break mb-0">${escapeHtml(result.message)}</p>` : ''}</div></header>`;
}

export function renderSnapshotDiff(results, readOnly = false, includeSummary = true) {
  return results.map((result) => {
    const summary = failureSummary(result);
    const next = readOnly && result.status === 'failed'
      ? 'Review the changed words and lines. Update the snapshot outside Production, review and commit the file, then rerun the test.'
      : summary.next;
    const rows = Array.isArray(result.diff) ? result.diff : [];
    const guidance = `<aside class="fs-snapshot-next" aria-label="Next step"><h4>Next step</h4><p>${escapeHtml(next)}</p></aside>`;
    const diff = result.status === 'failed' && rows.length > 0
      ? `<section class="fs-snapshot-section" aria-label="HTML changes"><h4>HTML changes</h4>
        ${guidance}
        <div class="fs-snapshot-diff" tabindex="0" role="region" aria-label="Snapshot changes"><table><colgroup><col style="width:1.5rem"><col style="width:4.5rem"><col style="width:4rem"><col></colgroup><thead><tr><th aria-label="Change"></th><th>Should</th><th>Got</th><th>HTML</th></tr></thead><tbody>${renderDiffRows(focusedRows(rows))}</tbody></table></div></section>`
      : '';
    const fullOutput = result.expected || result.actual
      ? `<details class="fs-snapshot-section"><summary>Show full output</summary><h4 class="mt-3">Snapshot</h4><pre>${escapeHtml(result.expected)}</pre><h4>Current output</h4><pre>${escapeHtml(result.actual)}</pre></details>` : '';
    const trace = result.trace
      ? `<section class="fs-snapshot-section" aria-label="Stack trace"><h4>Stack trace</h4><pre>${escapeHtml(result.trace)}</pre></section>` : '';
    return `${includeSummary ? renderSnapshotSummary(result) : ''}
      ${diff || guidance}${trace}${fullOutput}`;
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
  let feedbackError = false;
  let updateError = null;
  let updating = false;
  let closed = false;
  let modal;
  const content = () => html`
    <style>
      .fs-snapshot-modal .modal-body {overflow:hidden}
      .fs-snapshot-view {display:grid;flex:1;min-height:0;grid-template-columns:minmax(0,1fr);grid-template-rows:minmax(0,1fr);gap:1rem}
      .fs-snapshot-view.fs-snapshot-multiple {grid-template-columns:minmax(9rem,25%) minmax(0,1fr)}
      .fs-snapshot-view nav {display:flex;flex-direction:column;gap:.5rem;min-height:0;overflow:auto;overscroll-behavior:contain}
      .fs-snapshot-view nav button {display:flex;flex-shrink:0;flex-direction:column;align-items:stretch;gap:.25rem;text-align:left;white-space:normal;overflow-wrap:anywhere;border-left:3px solid var(--typo3-state-danger-border-color,#c83c3c);padding:.75rem}
      .fs-snapshot-view nav button.fs-snapshot-nav-warning {border-left-color:var(--typo3-state-warning-border-color,#b87a00)}
      .fs-snapshot-view nav button.fs-snapshot-nav-success {border-left-color:var(--typo3-state-success-border-color,#338449)}
      .fs-snapshot-view nav button[aria-current="true"] {background:var(--typo3-surface-container-info,#e0f1f7);color:var(--typo3-surface-container-info-text,#005c80);outline:2px solid var(--typo3-state-info-border-color,#0078a8);outline-offset:-2px;font-weight:bold}
      .fs-snapshot-view section {min-width:0}
      .fs-snapshot-view > section {min-height:0;overflow:auto;overscroll-behavior:contain;scrollbar-gutter:stable}
      .fs-snapshot-overview {display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:.75rem}
      .fs-snapshot-summary {display:flex;align-items:flex-start;gap:1rem;padding:1rem;border:1px solid;border-radius:var(--typo3-component-border-radius,.25rem)}
      .fs-snapshot-summary > div {min-width:0;display:grid;gap:.5rem}
      .fs-snapshot-summary h3 {font-size:1.2rem;font-weight:bold}
      .fs-snapshot-tone-danger {background:var(--typo3-surface-container-danger,#fce8e8);border-color:var(--typo3-state-danger-border-color,#c83c3c);color:var(--typo3-surface-container-danger-text,#8d2020)}
      .fs-snapshot-tone-warning {background:var(--typo3-surface-container-warning,#fff3d6);border-color:var(--typo3-state-warning-border-color,#b87a00);color:var(--typo3-surface-container-warning-text,#795000)}
      .fs-snapshot-tone-success {background:var(--typo3-surface-container-success,#e4f4e8);border-color:var(--typo3-state-success-border-color,#338449);color:var(--typo3-surface-container-success-text,#225d32)}
      .fs-snapshot-state {font-size:1.25rem;font-weight:bold;line-height:1.5}
      .fs-snapshot-summary h3,.fs-snapshot-summary dl,.fs-snapshot-summary dd {margin:0}
      .fs-snapshot-summary dt {font-size:.8rem;font-weight:normal}
      .fs-snapshot-summary code {color:inherit;overflow-wrap:anywhere}
      .fs-snapshot-next {margin:0 0 1rem}
      .fs-snapshot-next p {margin:0}
      .fs-snapshot-next h4,.fs-snapshot-section h4 {font-size:1rem;font-weight:bold;margin:0 0 .5rem}
      .fs-snapshot-section {margin-top:1rem;padding:1rem;border:1px solid var(--typo3-component-border-color);border-radius:var(--typo3-component-border-radius,.25rem)}
      .fs-snapshot-context {display:grid;grid-template-columns:minmax(0,1fr);gap:.75rem;margin:0}
      .fs-snapshot-context > div {padding:.5rem .75rem;background:var(--typo3-surface-container-lowest,#fff);border:1px solid var(--typo3-component-border-color);border-radius:var(--typo3-component-border-radius,.25rem)}
      .fs-snapshot-context dt {font-size:.8rem;font-weight:normal;color:var(--typo3-text-color-secondary)}
      .fs-snapshot-context dd {margin:0;overflow-wrap:anywhere}
      .fs-snapshot-actions {margin:1rem 0;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
      .fs-snapshot-actions [role="status"] {flex-basis:100%;overflow-wrap:anywhere}
      .fs-snapshot-view pre {white-space:pre-wrap;overflow-wrap:anywhere;max-height:45vh;overflow:auto}
      .fs-snapshot-diff {max-height:45vh;overflow:auto;border:1px solid var(--typo3-component-border-color)}
      .fs-snapshot-diff table {width:100%;font-family:monospace;font-size:.85rem;border-collapse:collapse;table-layout:fixed}
      .fs-snapshot-diff th {position:sticky;top:0;background:var(--typo3-surface-container-lowest,#fff)}
      .fs-snapshot-diff th,.fs-snapshot-diff td {padding:.25rem .5rem;vertical-align:top}
      .fs-snapshot-diff td:last-child {white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word}
      .fs-snapshot-diff td code {color:inherit}
      .fs-snapshot-line-removed {background:var(--typo3-surface-container-danger,#fce8e8)}
      .fs-snapshot-line-added {background:var(--typo3-surface-container-success,#e4f4e8)}
      .fs-snapshot-removed {background:var(--typo3-state-danger-bg,#fce8e8);color:var(--typo3-state-danger-color,#b00020);text-decoration:line-through;font-weight:bold}
      .fs-snapshot-added {background:var(--typo3-state-success-bg,#e4f4e8);color:var(--typo3-state-success-color,#006b35);text-decoration:underline;font-weight:bold}
      @media (max-width:600px) {
        .fs-snapshot-view.fs-snapshot-multiple {grid-template-columns:minmax(0,1fr);grid-template-rows:minmax(0,10rem) minmax(0,1fr)}
        .fs-snapshot-overview {grid-template-columns:minmax(0,1fr)}
        .fs-snapshot-context {grid-template-columns:repeat(2,minmax(0,1fr))}
      }
    </style>
    <div class="fs-snapshot-view ${results.length > 1 ? 'fs-snapshot-multiple' : ''}">
      ${results.length > 1 ? html`<nav aria-label="Failed variants">${results.map((result, index) => html`
        <button type="button" class=${`btn btn-default fs-snapshot-nav-${failureSummary(result).tone}`} aria-current=${index === selected ? 'true' : 'false'}
          @click=${() => { selected = index; feedback = ''; feedbackError = false; updateError = null; update(); }}><span>${result.identifier}</span><small class=${`text-${failureSummary(result).tone}`}>${failureSummary(result).title}</small></button>
      `)}</nav>` : ''}
      <section aria-label="Failure details">
        <p class="text-muted" role="status" aria-live="polite">Result ${selected + 1} of ${results.length}</p>
        <div class="fs-snapshot-overview">
          ${unsafeHTML(renderSnapshotSummary(results[selected]))}
          ${options.context?.site ? html`<dl class="fs-snapshot-context"><div><dt>Site</dt><dd>${options.context.site}</dd></div><div><dt>Language</dt><dd>${options.context.language}</dd></div></dl>` : ''}
        </div>
        <div class="fs-snapshot-actions">
          ${options.updateSnapshot && options.variantIdentifiers?.includes(results[selected].identifier) && ['failed', 'missing', 'created', 'updated', 'passed'].includes(results[selected].status) ? html`
            <button type="button" class="btn btn-warning"
              ?disabled=${updating || options.readOnly || !options.canUpdate() || !['failed', 'missing'].includes(results[selected].status)}
              title=${options.readOnly ? 'Production: snapshot files are read-only' : `${results[selected].status === 'missing' ? 'Create' : 'Replace'} this variant’s snapshot for the displayed site and language`}
              @click=${async () => {
                if (updating) return;
                const index = selected;
                updating = true;
                feedback = '';
                feedbackError = false;
                updateError = null;
                update();
                try {
                  const result = await options.updateSnapshot(results[index].identifier);
                  if (result) {
                    results[index] = result;
                    if (index === selected) {
                      feedback = ['created', 'updated'].includes(result.status) ? `Snapshot ${result.status}. Review and commit the file, then rerun.` : result.message || 'The snapshot is already up to date.';
                      feedbackError = result.status === 'error';
                    }
                  }
                } catch (error) {
                  if (index === selected) {
                    updateError = { message: error?.message || 'Could not update the snapshot.', trace: error?.trace || '' };
                  }
                } finally {
                  updating = false;
                  update();
                }
              }}>${results[selected].status === 'missing' ? 'Create snapshot' : 'Update snapshot'}</button>` : ''}
          ${options.openComponent && options.variantIdentifiers?.includes(results[selected].identifier) ? html`
            <button type="button" class="btn btn-default" @click=${() => { const identifier = results[selected].identifier; modal.hideModal(); options.openComponent(identifier, options.context); }}>Go to Component</button>` : ''}
          ${results[selected].path ? html`<button type="button" class="btn btn-default" @click=${async () => {
            const index = selected;
            try {
              await navigator.clipboard.writeText(results[index].path);
              if (selected === index) { feedback = 'Snapshot file location copied.'; feedbackError = false; }
            } catch {
              if (selected === index) { feedback = 'Could not copy snapshot file location.'; feedbackError = true; }
            }
            update();
          }}>Copy Snapshot file location</button>` : ''}
          <button type="button" class="btn btn-default" @click=${async () => {
            const index = selected;
            try {
              await navigator.clipboard.writeText(copyDetails(results[index], options.context));
              if (selected === index) { feedback = 'Details copied.'; feedbackError = false; }
            } catch {
              if (selected === index) { feedback = 'Could not copy details. Select and copy the output manually.'; feedbackError = true; }
            }
            update();
          }}>Copy details</button>
          ${options.readOnly ? html`<span class="text-warning">Production: snapshot files are read-only.</span>` : ''}
          <span class=${feedbackError ? 'text-danger' : ['created', 'updated'].includes(results[selected].status) ? 'text-warning' : 'text-success'} role="status" aria-live="polite">${feedback}</span>
        </div>
        ${updateError ? html`<aside class="alert alert-danger" role="alert" aria-label="Snapshot update error">
          <h4>Snapshot update failed</h4><p class="mb-0 text-break">${updateError.message}</p>
          ${updateError.trace ? html`<details class="mt-2"><summary>Update error stack trace</summary><pre class="mt-2">${updateError.trace}</pre></details>` : ''}
        </aside>` : ''}
        ${unsafeHTML(renderSnapshotDiff([results[selected]], options.readOnly, false))}
      </section>
    </div>`;
  const update = () => {
    if (closed) return;
    modal.modalTitle = `${failureSummary(results[selected]).title}: ${results[selected].identifier}`;
    modal.templateResultContent = content();
  };
  modal = Modal.advanced({
    title: `${failureSummary(results[0]).title}: ${results[0].identifier}`,
    content: content(),
    size: Modal.sizes.large,
    additionalCssClasses: ['fs-snapshot-modal'],
    buttons: [{ text: 'Close', active: true, btnClass: 'btn-default', trigger: () => modal.hideModal() }],
  });
  modal.addEventListener('typo3-modal-hidden', () => { closed = true; trigger?.focus(); }, { once: true });
}
