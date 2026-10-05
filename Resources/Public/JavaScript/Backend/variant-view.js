import DocumentService from '@typo3/core/document-service.js';
import { getVariantState } from '@andersundsehr/frontend-studio/backend/variant-state.js';

export default class FrontendStudioVariantView {
  static async initialize() {
    await Promise.all(Array.from(document.querySelectorAll('[data-frontend-studio-variant-view]'), async (root) => {
      const view = getVariantState(root);
      const features = [];
      if (root.querySelector('[data-frontend-studio-variant-frame]') !== null) {
        features.push(import('@andersundsehr/frontend-studio/backend/variant-preview.js').then(({ default: Preview }) => {
          view.mount('preview', () => new Preview(root, view));
        }));
      }
      if (root.dataset.componentChangeStreamUri && typeof EventSource !== 'undefined') {
        features.push(import('@andersundsehr/frontend-studio/backend/variant-file-watcher.js').then(({ default: Watcher }) => {
          view.mount('watcher', () => new Watcher(root, view));
        }));
      }
      await Promise.all(features);
    }));
  }
}

DocumentService.ready().then(() => FrontendStudioVariantView.initialize());
