import VariantPanel from '@andersundsehr/frontend-studio/backend/variant-panel.js';

export default class VariantHtml extends VariantPanel {
  constructor(root, view) {
    super(root, view, 'highlighted-fragment');
    this.container = root.querySelector('[data-frontend-studio-variant-html]');
    this.status = root.querySelector('[data-frontend-studio-variant-html-status]');
  }

  render(source) {
    this.container.innerHTML = source;
  }

  setStatus(message, isError = false) {
    this.status.textContent = message === 'Loading...' ? 'Loading rendered HTML...' : message;
    this.status.hidden = message === '';
    this.status.classList.toggle('is-error', isError);
  }
}
