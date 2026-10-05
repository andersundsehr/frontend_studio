import DocumentService from '@typo3/core/document-service.js';
import VariantFeature from '@andersundsehr/frontend-studio/backend/variant-lifecycle.js';
import VariantPreview from '@andersundsehr/frontend-studio/backend/variant-preview.js';
import { getVariantState } from '@andersundsehr/frontend-studio/backend/variant-state.js';
import { renderMarkdown } from '@andersundsehr/frontend-studio/backend/markdown-editor.bundle.js';

export default class ComponentOverview extends VariantFeature {
  constructor(root, view) {
    super(root, view);
    this.frames = Array.from(root.querySelectorAll('[data-overview-frame]'));
    this.frameCleanup = new Map();
    this.expand = root.querySelector('[data-overview-expand]');
    const source = root.querySelector('[data-overview-markdown]');
    if (source) root.querySelector('[data-overview-description]').innerHTML = renderMarkdown(source.textContent);
    if (root.querySelector('[data-frontend-studio-variant-frame]')) {
      view.mount('preview', () => new VariantPreview(root, view));
    }
    this.listen(view, 'context', () => this.updateContext());
    this.listen(view, 'suspend', () => this.clearFrames());
    this.listen(view, 'resume', () => this.frames.forEach((frame) => this.observeFrame(frame)));
    this.frames.forEach((frame) => {
      this.listen(frame, 'load', () => this.observeFrame(frame));
      this.listen(frame, 'error', () => this.fallback(frame));
      // An eager iframe can finish before the module loads. Do not eagerly load lazy frames.
      try {
        if (frame.contentDocument?.readyState === 'complete' && frame.contentDocument.URL !== 'about:blank') this.observeFrame(frame);
      } catch { this.fallback(frame); }
    });
    this.listen(this.expand, 'click', () => {
      const expanded = this.expand.getAttribute('aria-expanded') !== 'true';
      this.expand.setAttribute('aria-expanded', String(expanded));
      this.expand.textContent = expanded ? 'Collapse preview' : 'Expand preview';
      this.frames[0]?.closest('[data-overview-frame-container]').classList.toggle('is-expanded', expanded);
    });
  }

  updateContext() {
    const context = this.view.previewUri ? new URL(this.view.previewUri, window.location.href) : null;
    this.frames.slice(1).forEach((frame) => {
      this.frameCleanup.get(frame)?.();
      if (!context) { frame.src = 'about:blank'; return; }
      const url = new URL(context);
      url.searchParams.set('componentVariant', frame.dataset.variantIdentifier);
      url.searchParams.delete('componentVariantValues');
      url.searchParams.delete('componentVariantSlots');
      if (frame.src !== url.toString()) frame.src = url.toString();
    });
    this.root.querySelectorAll('[data-overview-variant-link]').forEach((link) => {
      const url = new URL(link.href, window.location.href);
      for (const parameter of ['site', 'language']) {
        const value = context?.searchParams.get(parameter);
        if (value) url.searchParams.set(parameter, value);
        else url.searchParams.delete(parameter);
      }
      link.href = url.toString();
    });
  }

  fallback(frame) {
    this.frameCleanup.get(frame)?.();
    frame.style.height = '320px';
    frame.closest('section').querySelector('[data-overview-frame-status]').textContent = 'Automatic preview sizing is unavailable. Scroll the preview or open the variant.';
  }

  observeFrame(frame) {
    this.frameCleanup.get(frame)?.();
    let doc;
    try {
      doc = frame.contentDocument;
      if (!doc?.body) { this.fallback(frame); return; }
      if (doc.URL === 'about:blank') return;
    } catch { this.fallback(frame); return; }
    if (!doc) { this.fallback(frame); return; }
    let active = true;
    let scheduled = null;
    const measure = () => {
      scheduled = null;
      if (!active || this.destroyed || !frame.isConnected) return;
      try {
        // Remove the old viewport-height floor so previews can shrink as well as grow.
        frame.style.height = '1px';
        const height = Math.max(80, doc.documentElement.scrollHeight, doc.body.scrollHeight);
        frame.style.height = `${height}px`;
        frame.closest('section').querySelector('[data-overview-frame-status]').textContent = '';
        if (frame === this.frames[0] && this.expand) this.expand.hidden = height <= 480;
      } catch { this.fallback(frame); }
    };
    const schedule = () => {
      if (active && scheduled === null) scheduled = window.requestAnimationFrame(measure);
    };
    const resize = new ResizeObserver(schedule);
    resize.observe(doc.body);
    const mutation = new MutationObserver(schedule);
    mutation.observe(doc.body, { attributes: true, childList: true, characterData: true, subtree: true });
    doc.addEventListener('load', schedule, true);
    window.addEventListener('resize', schedule);
    doc.fonts?.ready.then(schedule);
    this.frameCleanup.set(frame, () => {
      active = false;
      resize.disconnect(); mutation.disconnect();
      doc.removeEventListener('load', schedule, true);
      window.removeEventListener('resize', schedule);
      if (scheduled !== null) window.cancelAnimationFrame(scheduled);
      this.frameCleanup.delete(frame);
    });
    schedule();
  }

  clearFrames() {
    this.frameCleanup.forEach((cleanup) => cleanup());
  }

  destroy() {
    this.clearFrames();
    super.destroy();
  }
}

DocumentService.ready().then(() => document.querySelectorAll('[data-component-overview]').forEach((root) => {
  const view = getVariantState(root);
  view.mount(root, () => new ComponentOverview(root, view));
}));
