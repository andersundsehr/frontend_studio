import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import PersistentStorage from '@typo3/backend/storage/persistent.js';

class FrontendStudioVariantView {
  static sidebarWidthStorageKey = 'frontendStudio.variantView.sidebarWidth';

  static activeTabStorageKey = 'frontendStudio.variantView.activeTab';

  static defaultSidebarWidth = 360;

  static minimumSidebarWidth = 280;

  static minimumPreviewWidth = 320;

  constructor(root) {
    this.root = root;
    this.previewUri = root.dataset.previewUri || '';
    this.variantIdentifier = root.dataset.variantIdentifier || '';
    this.componentFilePath = root.dataset.componentFilePath || '';
    this.iframe = root.querySelector('[data-frontend-studio-variant-frame]');
    this.workspace = root.querySelector('.frontend-studio-variant-workspace');
    this.sidebar = root.querySelector('.frontend-studio-variant-sidebar');
    this.sidebarResizeHandle = root.querySelector('[data-frontend-studio-variant-sidebar-resize]');
    this.copyComponentPathButton = root.querySelector('[data-frontend-studio-copy-component-path]');
    this.saveButton = root.querySelector('[data-frontend-studio-variant-save]');
    this.resetButton = root.querySelector('[data-frontend-studio-variant-reset]');
    this.saveState = root.querySelector('[data-frontend-studio-variant-save-state]');
    this.tabButtons = Array.from(root.querySelectorAll('[data-frontend-studio-variant-tab]'));
    this.tabPanels = Array.from(root.querySelectorAll('[data-frontend-studio-variant-tab-panel]'));
    this.htmlContainer = root.querySelector('[data-frontend-studio-variant-html]');
    this.htmlStatus = root.querySelector('[data-frontend-studio-variant-html-status]');
    this.fields = Array.from(root.querySelectorAll('[data-frontend-studio-variant-value]'));
    this.savedValues = {};
    this.hasUnsavedChanges = false;
    this.activeTab = this.readInitialActiveTab();
    this.htmlRefreshTimeout = null;
    this.renderedHtmlPreviewUrl = '';
    this.renderedHtmlRequestId = 0;
    this.sidebarWidth = FrontendStudioVariantView.defaultSidebarWidth;
    this.isResizingSidebar = false;
  }

  initialize() {
    if (this.previewUri === '' || this.iframe === null) {
      return;
    }

    this.savedValues = this.collectValues();
    this.renderedHtmlPreviewUrl = this.buildRenderedHtmlUrl().toString();
    this.updateDirtyState();
    this.initializeTabs();
    this.initializeSidebarResize();

    this.fields.forEach((field) => {
      field.addEventListener('input', () => {
        this.updateDirtyState();
        this.updatePreview();
        this.scheduleRenderedHtmlRefresh();
      });
    });

    this.saveButton?.addEventListener('click', (event) => {
      event.preventDefault();
      this.saveValues();
    });

    this.resetButton?.addEventListener('click', (event) => {
      event.preventDefault();
      this.resetValues();
    });

    this.copyComponentPathButton?.addEventListener('click', (event) => {
      event.preventDefault();
      this.copyComponentFilePath();
    });

    document.addEventListener('keydown', (event) => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
        event.preventDefault();
        this.saveValues();
      }
    });
  }

  initializeSidebarResize() {
    if (this.workspace === null || this.sidebar === null || this.sidebarResizeHandle === null) {
      return;
    }

    this.applySidebarWidth(this.readInitialSidebarWidth());

    this.sidebarResizeHandle.addEventListener('pointerdown', (event) => {
      if (event.button !== 0) {
        return;
      }

      event.preventDefault();
      this.isResizingSidebar = true;
      this.root.classList.add('is-resizing-sidebar');
      this.sidebarResizeHandle.setPointerCapture(event.pointerId);
      this.updateSidebarWidthFromPointer(event.clientX);
    });

    this.sidebarResizeHandle.addEventListener('pointermove', (event) => {
      if (!this.isResizingSidebar) {
        return;
      }

      event.preventDefault();
      this.updateSidebarWidthFromPointer(event.clientX);
    });

    this.sidebarResizeHandle.addEventListener('pointerup', (event) => {
      this.finishSidebarResize(event.pointerId);
    });

    this.sidebarResizeHandle.addEventListener('pointercancel', (event) => {
      this.finishSidebarResize(event.pointerId);
    });
  }

  readInitialSidebarWidth() {
    const initialWidth = Number.parseInt(
      getComputedStyle(this.root).getPropertyValue('--frontend-studio-variant-sidebar-width'),
      10,
    );

    if (Number.isFinite(initialWidth)) {
      return initialWidth;
    }

    return FrontendStudioVariantView.defaultSidebarWidth;
  }

  updateSidebarWidthFromPointer(pointerClientX) {
    if (this.workspace === null) {
      return;
    }

    const workspaceRect = this.workspace.getBoundingClientRect();
    const width = workspaceRect.right - pointerClientX;

    this.applySidebarWidth(width);
  }

  applySidebarWidth(width) {
    if (this.workspace === null) {
      return;
    }

    const workspaceWidth = this.workspace.getBoundingClientRect().width;
    const maximumSidebarWidth = Math.max(
      FrontendStudioVariantView.minimumSidebarWidth,
      Math.min(workspaceWidth - FrontendStudioVariantView.minimumPreviewWidth, window.innerWidth * 0.7),
    );
    const clampedWidth = Math.min(
      Math.max(width, FrontendStudioVariantView.minimumSidebarWidth),
      maximumSidebarWidth,
    );

    this.sidebarWidth = clampedWidth;
    this.root.style.setProperty('--frontend-studio-variant-sidebar-width', `${clampedWidth}px`);
    this.sidebarResizeHandle?.setAttribute('aria-valuenow', String(Math.round(clampedWidth)));
  }

  finishSidebarResize(pointerId) {
    if (!this.isResizingSidebar) {
      return;
    }

    this.isResizingSidebar = false;
    this.root.classList.remove('is-resizing-sidebar');

    if (this.sidebarResizeHandle?.hasPointerCapture(pointerId)) {
      this.sidebarResizeHandle.releasePointerCapture(pointerId);
    }

    this.persistSidebarWidth(Math.round(this.sidebarWidth));
  }

  async persistSidebarWidth(width) {
    try {
      await PersistentStorage.set(FrontendStudioVariantView.sidebarWidthStorageKey, width);
    } catch {
      // Ignore persistence failures; resizing still works for the current page load.
    }
  }

  initializeTabs() {
    this.activateTab(this.activeTab, false);

    this.tabButtons.forEach((button) => {
      button.addEventListener('click', () => {
        this.activateTab(button.dataset.frontendStudioVariantTab || 'values');
      });
    });
  }

  readInitialActiveTab() {
    const activeButton = this.tabButtons.find((button) => button.classList.contains('is-active'));
    const activeTab = activeButton?.dataset.frontendStudioVariantTab || this.root.dataset.activeTab || 'values';

    return this.isValidTabName(activeTab) ? activeTab : 'values';
  }

  activateTab(tabName, persist = true) {
    if (!this.isValidTabName(tabName)) {
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

    if (tabName === 'html') {
      this.refreshRenderedHtml();
    }

    if (persist) {
      this.persistActiveTab(tabName);
    }
  }

  isValidTabName(tabName) {
    return this.tabButtons.some((button) => button.dataset.frontendStudioVariantTab === tabName);
  }

  async persistActiveTab(tabName) {
    try {
      await PersistentStorage.set(FrontendStudioVariantView.activeTabStorageKey, tabName);
    } catch {
      // Ignore persistence failures; switching tabs still works for the current page load.
    }
  }

  collectValues() {
    const values = {};

    this.fields.forEach((field) => {
      const name = field.dataset.fixtureName || field.name || '';
      if (name === '') {
        return;
      }

      values[name] = field.value;
    });

    return values;
  }

  buildPreviewUrl() {
    const previewUrl = new URL(this.previewUri, window.location.href);

    if (this.fields.length > 0) {
      previewUrl.searchParams.set('componentVariantValues', JSON.stringify(this.collectValues()));
    }

    return previewUrl;
  }

  buildRenderedHtmlUrl() {
    const renderedHtmlUrl = this.buildPreviewUrl();
    renderedHtmlUrl.searchParams.set('frontendStudioPreviewFormat', 'highlighted-fragment');

    return renderedHtmlUrl;
  }

  updatePreview() {
    this.iframe.src = this.buildPreviewUrl().toString();
  }

  scheduleRenderedHtmlRefresh() {
    if (this.activeTab !== 'html') {
      return;
    }

    window.clearTimeout(this.htmlRefreshTimeout);
    this.htmlRefreshTimeout = window.setTimeout(() => {
      this.refreshRenderedHtml();
    }, 250);
  }

  async refreshRenderedHtml() {
    if (this.htmlContainer === null) {
      return;
    }

    const previewUrl = this.buildRenderedHtmlUrl().toString();
    if (previewUrl === this.renderedHtmlPreviewUrl) {
      return;
    }

    this.renderedHtmlPreviewUrl = previewUrl;
    const requestId = ++this.renderedHtmlRequestId;
    this.setRenderedHtmlStatus('Loading rendered HTML...');

    try {
      const response = await fetch(previewUrl, {
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
        },
      });

      const renderedHtml = await response.text();
      if (requestId !== this.renderedHtmlRequestId) {
        return;
      }

      if (!response.ok) {
        throw new Error(renderedHtml || `The rendered HTML request failed with status ${response.status}.`);
      }

      this.renderHtmlSource(renderedHtml);
      this.setRenderedHtmlStatus('');
    } catch (error) {
      if (requestId !== this.renderedHtmlRequestId) {
        return;
      }

      this.renderedHtmlPreviewUrl = '';
      this.setRenderedHtmlStatus(error?.message || 'The rendered HTML could not be loaded.', true);
    }
  }

  renderHtmlSource(renderedHtml) {
    if (this.htmlContainer === null) {
      return;
    }

    this.htmlContainer.innerHTML = renderedHtml;
  }

  setRenderedHtmlStatus(message, isError = false) {
    if (this.htmlStatus === null) {
      return;
    }

    this.htmlStatus.textContent = message;
    this.htmlStatus.hidden = message === '';
    this.htmlStatus.classList.toggle('is-error', isError);
  }

  updateDirtyState() {
    this.hasUnsavedChanges = JSON.stringify(this.collectValues()) !== JSON.stringify(this.savedValues);

    if (this.saveState !== null) {
      this.saveState.hidden = !this.hasUnsavedChanges;
    }

    if (this.saveButton !== null) {
      this.saveButton.disabled = !this.hasUnsavedChanges;
    }

    if (this.resetButton !== null) {
      this.resetButton.disabled = !this.hasUnsavedChanges;
    }
  }

  resetValues() {
    this.fields.forEach((field) => {
      const name = field.dataset.fixtureName || field.name || '';
      if (name === '' || this.savedValues[name] === undefined) {
        return;
      }

      field.value = this.savedValues[name];
    });

    this.updateDirtyState();
    this.updatePreview();
    this.renderedHtmlPreviewUrl = '';
    this.scheduleRenderedHtmlRefresh();
  }

  async saveValues() {
    if (this.variantIdentifier === '' || this.saveButton === null || !this.hasUnsavedChanges) {
      return;
    }

    this.saveButton.disabled = true;

    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.frontend_studio_component_tree_update_variant_values)
        .post({
          identifier: this.variantIdentifier,
          values: this.collectValues(),
        });
      const payload = await response.resolve();

      if (payload.success !== true || payload.variant === undefined) {
        throw new Error(payload.message || 'The variant values could not be saved.');
      }

      Notification.success('Variant saved', 'The variant values were written to the fixture file.');
      this.savedValues = this.collectValues();
      this.updateDirtyState();
      this.renderedHtmlPreviewUrl = '';
      this.scheduleRenderedHtmlRefresh();
    } catch (error) {
      const payload = typeof error?.resolve === 'function' ? await error.resolve() : null;
      Notification.error('Variant save failed', payload?.message || error?.message || 'The variant values could not be saved.');
    } finally {
      this.updateDirtyState();
    }
  }

  async copyComponentFilePath() {
    if (this.componentFilePath === '') {
      return;
    }

    try {
      await navigator.clipboard.writeText(this.componentFilePath);
      Notification.success('File path copied', this.componentFilePath);
    } catch (error) {
      Notification.error('Copy failed', error?.message || 'The component file path could not be copied.');
    }
  }

  static initialize() {
    document.querySelectorAll('[data-frontend-studio-variant-view]').forEach((root) => {
      new FrontendStudioVariantView(root).initialize();
    });
  }
}

export default FrontendStudioVariantView;

FrontendStudioVariantView.initialize();
