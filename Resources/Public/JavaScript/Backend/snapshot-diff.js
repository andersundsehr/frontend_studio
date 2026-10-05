import Modal from '@typo3/backend/modal.js';

export function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character]));
}

export function renderSnapshotDiff(results) {
  return results.map((result) => {
    const expected = (result.expected || '').split('\n');
    const actual = (result.actual || '').split('\n');
    const lines = Array.from({ length: Math.max(expected.length, actual.length) }, (_, index) => {
      if (expected[index] === actual[index]) return `  ${escapeHtml(expected[index])}`;
      if (expected[index] === '<!-- frontend-studio:dynamic-line -->') return `<span style="color:var(--typo3-text-color-warning,#805600)">~ ${escapeHtml(expected[index])}\n  ${escapeHtml(actual[index])}</span>`;
      return (index < expected.length ? `<span style="color:var(--typo3-text-color-danger,#b00020)">- ${escapeHtml(expected[index])}</span>\n` : '')
        + (index < actual.length ? `<span style="color:var(--typo3-text-color-success,#006b35)">+ ${escapeHtml(actual[index])}</span>` : '');
    });
    return `<details open><summary>${escapeHtml(result.identifier)} — ${escapeHtml(result.status)}</summary><p>${escapeHtml(result.message)}</p><p><code>${escapeHtml(result.path || '')}</code></p><pre style="white-space:pre-wrap;overflow-wrap:anywhere;max-height:55vh;overflow:auto" tabindex="0" aria-label="Expected and actual HTML diff">${lines.join('\n')}</pre></details>`;
  }).join('<hr>');
}

export async function showSnapshotDiff(results) {
  const [{ html }, { unsafeHTML }] = await Promise.all([import('lit'), import('lit/directives/unsafe-html.js')]);
  const trigger = document.activeElement;
  const modal = Modal.advanced({
    title: 'HTML snapshot results',
    content: html`<p>− expected · + actual · ~ ignored dynamic line</p>${unsafeHTML(renderSnapshotDiff(results))}`,
    size: Modal.sizes.large,
    buttons: [{ text: 'Close', active: true, btnClass: 'btn-default', trigger: () => modal.hideModal() }],
  });
  modal.addEventListener('typo3-modal-hidden', () => trigger?.focus(), { once: true });
}
