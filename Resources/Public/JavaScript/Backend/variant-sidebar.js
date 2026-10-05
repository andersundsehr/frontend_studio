import DocumentService from '@typo3/core/document-service.js';
import PersistentStorage from '@typo3/backend/storage/persistent.js';
import VariantFeature from '@andersundsehr/frontend-studio/backend/variant-lifecycle.js';
import { getVariantState } from '@andersundsehr/frontend-studio/backend/variant-state.js';

class VariantSidebar extends VariantFeature {
  static activeTabStorageKey = 'frontendStudio.variantView.activeTab';

  constructor(root, view) {
    super(root, view);
    this.tabButtons = Array.from(root.querySelectorAll('[data-frontend-studio-variant-tab]'));
    this.tabPanels = Array.from(root.querySelectorAll('[data-frontend-studio-variant-tab-panel]'));
    this.panels = new Map();
    const activeButton = this.tabButtons.find((button) => button.classList.contains('is-active'));
    this.activeTab = activeButton?.dataset.frontendStudioVariantTab || root.dataset.activeTab || 'values';
    this.activateTab(this.activeTab, false);
    this.tabButtons.forEach((button) => {
      this.listen(button, 'click', () => this.activateTab(button.dataset.frontendStudioVariantTab || 'values'));
    });
    ['values', 'context', 'files', 'saved'].forEach((type) => this.listen(view, type, () => {
      this.panels.forEach((panel) => panel.invalidate());
      this.refreshActivePanel(type === 'values' ? 250 : 0);
    }));
    if (view.root.querySelector('.frontend-studio-variant-workspace') !== null
      && view.root.querySelector('[data-frontend-studio-variant-sidebar-resize]') !== null) {
      import('@andersundsehr/frontend-studio/backend/variant-sidebar-resize.js').then(({ default: Resize }) => {
        view.mount('resize', () => {
          const resize = new Resize(view.root, view);
          resize.initialize();
          return resize;
        });
      });
    }
  }

  async activateTab(tabName, persist = true) {
    if (!this.tabButtons.some((button) => button.dataset.frontendStudioVariantTab === tabName)) {
      tabName = 'values';
    }
    this.activeTab = tabName;
    this.tabButtons.forEach((button) => {
      const isActive = button.dataset.frontendStudioVariantTab === tabName;
      button.classList.toggle('is-active', isActive);
      button.classList.toggle('active', isActive);
      button.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
    this.tabPanels.forEach((panel) => {
      const isActive = panel.dataset.frontendStudioVariantTabPanel === tabName;
      panel.classList.toggle('is-active', isActive);
      panel.classList.toggle('is-hidden', !isActive);
      panel.hidden = !isActive;
    });
    this.panels.forEach((panel, name) => {
      if (name !== tabName) {
        panel.invalidate();
      }
    });
    if (persist) {
      this.persistActiveTab(tabName);
    }
    const panelRoot = this.tabPanels.find((panel) => panel.dataset.frontendStudioVariantTabPanel === tabName);
    if (panelRoot === undefined || !['html', 'usage'].includes(tabName)) {
      return;
    }
    const module = tabName === 'html' ? await import('@andersundsehr/frontend-studio/backend/variant-html.js') : await import('@andersundsehr/frontend-studio/backend/variant-usage.js');
    if (this.destroyed || this.activeTab !== tabName) {
      return;
    }
    const panel = this.view.mount(panelRoot, () => new module.default(panelRoot, this.view));
    if (panel !== null) {
      this.panels.set(tabName, panel);
      panel.schedule();
    }
  }

  refreshActivePanel(delay) {
    this.panels.get(this.activeTab)?.schedule(delay);
  }

  async persistActiveTab(tabName) {
    try {
      await PersistentStorage.set(VariantSidebar.activeTabStorageKey, tabName);
    } catch {
      // Tab switching still works when persistence is unavailable.
    }
  }

  static initialize() {
    document.querySelectorAll('[data-frontend-studio-variant-sidebar]').forEach((root) => {
      const view = getVariantState(root);
      view.mount(root, () => new VariantSidebar(root, view));
    });
  }
}

export default VariantSidebar;

DocumentService.ready().then(() => VariantSidebar.initialize());
