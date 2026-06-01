import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import PersistentStorage from '@typo3/backend/storage/persistent.js';

const componentFileActionStartedEventName = 'frontend-studio:component-file-action-started';
const componentFileActionCancelledEventName = 'frontend-studio:component-file-action-cancelled';

class FrontendStudioVariantView {
  static sidebarWidthStorageKey = 'frontendStudio.variantView.sidebarWidth';

  static activeTabStorageKey = 'frontendStudio.variantView.activeTab';

  static defaultSidebarWidth = 360;

  static minimumSidebarWidth = 280;

  static minimumPreviewWidth = 320;

  constructor(root) {
    this.root = root;
    this.previewUri = root.dataset.previewUri || '';
    this.componentChangeStreamUri = root.dataset.componentChangeStreamUri || '';
    this.variantIdentifier = root.dataset.variantIdentifier || '';
    this.componentIdentifier = this.getComponentIdentifierFromVariantIdentifier(this.variantIdentifier);
    this.componentFilePath = root.dataset.componentFilePath || '';
    this.componentFluidTagName = root.dataset.componentFluidTagName || '';
    this.iframe = root.querySelector('[data-frontend-studio-variant-frame]');
    this.workspace = root.querySelector('.frontend-studio-variant-workspace');
    this.sidebar = root.querySelector('.frontend-studio-variant-sidebar');
    this.sidebarResizeHandle = root.querySelector('[data-frontend-studio-variant-sidebar-resize]');
    this.copyComponentPathButton = root.querySelector('[data-frontend-studio-copy-component-path]');
    this.copyFluidUsageButton = root.querySelector('[data-frontend-studio-copy-fluid-usage]');
    this.saveButton = root.querySelector('[data-frontend-studio-variant-save]');
    this.copyVariantButton = root.querySelector('[data-frontend-studio-variant-copy]');
    this.resetButton = root.querySelector('[data-frontend-studio-variant-reset]');
    this.saveState = root.querySelector('[data-frontend-studio-variant-save-state]');
    this.tabButtons = Array.from(root.querySelectorAll('[data-frontend-studio-variant-tab]'));
    this.tabPanels = Array.from(root.querySelectorAll('[data-frontend-studio-variant-tab-panel]'));
    this.htmlContainer = root.querySelector('[data-frontend-studio-variant-html]');
    this.htmlStatus = root.querySelector('[data-frontend-studio-variant-html-status]');
    this.fluidUsageCode = root.querySelector('[data-frontend-studio-fluid-usage-code]');
    this.fields = Array.from(root.querySelectorAll('[data-frontend-studio-variant-value]'));
    this.initialFieldValues = {};
    this.savedValues = {};
    this.hasUnsavedChanges = false;
    this.fluidUsageSnippet = '';
    this.activeTab = this.readInitialActiveTab();
    this.htmlRefreshTimeout = null;
    this.renderedHtmlPreviewUrl = '';
    this.renderedHtmlRequestId = 0;
    this.fluidUsageRequestId = 0;
    this.sidebarWidth = FrontendStudioVariantView.defaultSidebarWidth;
    this.isResizingSidebar = false;
    this.componentChangeEventSource = null;
    this.ignoreNextComponentFilesChanged = false;
  }

  initialize() {
    if (this.previewUri === '' || this.iframe === null) {
      return;
    }

    this.initialFieldValues = this.collectFieldValues();
    this.savedValues = this.collectValues();
    this.renderedHtmlPreviewUrl = this.buildRenderedHtmlUrl().toString();
    this.updateFluidUsageSnippet();
    this.updateDirtyState();
    this.initializeTabs();
    this.initializeSidebarResize();
    this.initializeComponentFileActionSuppression();
    this.initializeComponentChangeStream();

    this.fields.forEach((field) => {
      const handleFieldChange = () => {
        this.updateDirtyState();
        this.updateFluidUsageSnippet(true);
        this.updatePreview();
        this.scheduleRenderedHtmlRefresh();
      };

      field.addEventListener('input', handleFieldChange);
      field.addEventListener('change', handleFieldChange);
    });

    this.saveButton?.addEventListener('click', (event) => {
      event.preventDefault();
      this.saveValues();
    });

    this.copyVariantButton?.addEventListener('click', (event) => {
      event.preventDefault();
      this.promptCopyVariant();
    });

    this.resetButton?.addEventListener('click', (event) => {
      event.preventDefault();
      this.resetValues();
    });

    this.copyComponentPathButton?.addEventListener('click', (event) => {
      event.preventDefault();
      this.copyComponentFilePath();
    });

    this.copyFluidUsageButton?.addEventListener('click', (event) => {
      event.preventDefault();
      this.copyFluidUsageSnippet();
    });

    document.addEventListener('keydown', (event) => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
        event.preventDefault();
        this.saveValues();
      }
    });
  }

  initializeComponentChangeStream() {
    if (this.componentChangeStreamUri === '' || typeof EventSource === 'undefined') {
      return;
    }

    this.componentChangeEventSource = new EventSource(this.componentChangeStreamUri);
    this.componentChangeEventSource.addEventListener('component-files-changed', (event) => {
      this.handleComponentFilesChanged(event);
    });

    window.addEventListener('pagehide', () => {
      this.componentChangeEventSource?.close();
      this.componentChangeEventSource = null;
    }, { once: true });
  }

  initializeComponentFileActionSuppression() {
    top.document.addEventListener(componentFileActionStartedEventName, () => {
      this.ignoreNextComponentFilesChanged = true;
    });

    top.document.addEventListener(componentFileActionCancelledEventName, () => {
      this.ignoreNextComponentFilesChanged = false;
    });
  }

  handleComponentFilesChanged(event) {
    if (this.ignoreNextComponentFilesChanged) {
      this.ignoreNextComponentFilesChanged = false;
      return;
    }

    top.document.dispatchEvent(new CustomEvent('frontend-studio:component-files-changed', {
      detail: {
        variantIdentifier: this.variantIdentifier,
      },
    }));

    if (!this.isCurrentComponentAffected(event)) {
      return;
    }

    this.updatePreview();
    this.renderedHtmlPreviewUrl = '';
    this.refreshRenderedHtml();
    this.updateFluidUsageSnippet(true);

    if (!this.hasUnsavedChanges) {
      window.location.reload();
    }
  }

  isCurrentComponentAffected(event) {
    if (this.componentIdentifier === '') {
      return false;
    }

    const payload = this.parseComponentFilesChangedPayload(event);
    if (!Array.isArray(payload.componentIdentifiers)) {
      return false;
    }

    return payload.componentIdentifiers.includes(this.componentIdentifier);
  }

  parseComponentFilesChangedPayload(event) {
    try {
      return JSON.parse(event?.data || '{}');
    } catch {
      return {};
    }
  }

  getComponentIdentifierFromVariantIdentifier(variantIdentifier) {
    const identifierParts = variantIdentifier.split(':');
    if (identifierParts.length < 3) {
      return '';
    }

    return identifierParts.slice(0, -1).join(':');
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

      const value = this.readFieldValue(field);
      if (this.shouldSubmitField(field, name, value)) {
        values[name] = value;
      }
    });

    return values;
  }

  collectFieldValues() {
    const values = {};

    this.fields.forEach((field) => {
      const name = field.dataset.fixtureName || field.name || '';
      if (name === '') {
        return;
      }

      values[name] = this.readFieldValue(field);
    });

    return values;
  }

  shouldSubmitField(field, name, value) {
    if (field.dataset.fixtureValueDefined === 'true') {
      return true;
    }

    return JSON.stringify(value) !== JSON.stringify(this.initialFieldValues[name]);
  }

  readFieldValue(field) {
    const fixtureType = (field.dataset.fixtureType || '').toLowerCase();

    if (fixtureType === 'bool' || fixtureType === 'boolean') {
      return field.checked === true;
    }

    if (fixtureType === 'int' || fixtureType === 'integer') {
      return Number.isFinite(field.valueAsNumber) ? Math.trunc(field.valueAsNumber) : field.value;
    }

    if (fixtureType === 'float' || fixtureType === 'double') {
      return Number.isFinite(field.valueAsNumber) ? field.valueAsNumber : field.value;
    }

    if (fixtureType === 'null') {
      const value = field.value.trim();
      return value === '' || value.toLowerCase() === 'null' ? null : field.value;
    }

    if (this.isCompoundFixtureType(fixtureType)) {
      try {
        return JSON.parse(field.value);
      } catch {
        return field.value;
      }
    }

    return field.value;
  }

  isCompoundFixtureType(fixtureType) {
    return ['array', 'object', 'stdclass'].includes(fixtureType)
      || fixtureType.startsWith('array<')
      || fixtureType.startsWith('object(');
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

  buildFluidUsageUrl() {
    const fluidUsageUrl = this.buildPreviewUrl();
    fluidUsageUrl.searchParams.set('frontendStudioPreviewFormat', 'fluid-usage');

    return fluidUsageUrl;
  }

  updateFluidUsageSnippet(refreshHighlightedSnippet = false) {
    this.fluidUsageSnippet = this.buildFluidUsageSnippet();

    if (refreshHighlightedSnippet) {
      this.refreshFluidUsageSnippet();
    }
  }

  buildFluidUsageSnippet() {
    const tagName = this.componentFluidTagName.trim();
    if (tagName === '') {
      return '';
    }

    const attributes = Object.entries(this.collectValues())
      .map(([name, value]) => ` ${name}="${this.escapeFluidAttributeValue(this.formatFluidAttributeValue(value))}"`)
      .join('');

    return `<${tagName}${attributes} />`;
  }

  formatFluidAttributeValue(value) {
    if (value === null) {
      return 'null';
    }

    if (typeof value === 'boolean') {
      return value ? 'true' : 'false';
    }

    if (typeof value === 'number') {
      return String(value);
    }

    if (typeof value === 'string') {
      return value;
    }

    try {
      return JSON.stringify(value);
    } catch {
      return String(value);
    }
  }

  escapeFluidAttributeValue(value) {
    return value
      .replaceAll('&', '&amp;')
      .replaceAll('"', '&quot;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;');
  }

  dispatchComponentFileActionStarted(action = 'save') {
    top.document.dispatchEvent(new CustomEvent(componentFileActionStartedEventName, {
      detail: {
        action,
        variantIdentifier: this.variantIdentifier,
      },
    }));
  }

  dispatchComponentFileActionCancelled(action = 'save') {
    top.document.dispatchEvent(new CustomEvent(componentFileActionCancelledEventName, {
      detail: {
        action,
        variantIdentifier: this.variantIdentifier,
      },
    }));
  }

  updatePreview() {
    this.iframe.src = this.buildPreviewUrl().toString();
  }

  async refreshFluidUsageSnippet() {
    if (this.fluidUsageCode === null) {
      return;
    }

    const requestId = ++this.fluidUsageRequestId;

    try {
      const response = await fetch(this.buildFluidUsageUrl().toString(), {
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
        },
      });
      const fluidUsageSource = await response.text();
      if (requestId !== this.fluidUsageRequestId) {
        return;
      }

      if (!response.ok) {
        throw new Error(fluidUsageSource || `The Fluid usage request failed with status ${response.status}.`);
      }

      this.fluidUsageCode.innerHTML = fluidUsageSource;
    } catch {
      if (requestId === this.fluidUsageRequestId) {
        this.fluidUsageCode.textContent = this.fluidUsageSnippet;
      }
    }
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
      if (name === '' || this.initialFieldValues[name] === undefined) {
        return;
      }

      this.writeFieldValue(field, this.initialFieldValues[name]);
    });

    this.updateDirtyState();
    this.updateFluidUsageSnippet(true);
    this.updatePreview();
    this.renderedHtmlPreviewUrl = '';
    this.scheduleRenderedHtmlRefresh();
  }

  writeFieldValue(field, value) {
    const fixtureType = (field.dataset.fixtureType || '').toLowerCase();

    if (fixtureType === 'bool' || fixtureType === 'boolean') {
      field.checked = value === true;
      return;
    }

    if (value === null || value === undefined) {
      field.value = '';
      return;
    }

    if (this.isCompoundFixtureType(fixtureType) && typeof value !== 'string') {
      field.value = JSON.stringify(value, null, 2);
      return;
    }

    field.value = String(value);
  }

  async saveValues() {
    if (this.variantIdentifier === '' || this.saveButton === null || !this.hasUnsavedChanges) {
      return;
    }

    this.saveButton.disabled = true;
    this.dispatchComponentFileActionStarted();

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
      const savedValues = this.collectValues();
      this.savedValues = savedValues;
      this.initialFieldValues = this.collectFieldValues();
      this.fields.forEach((field) => {
        const name = field.dataset.fixtureName || field.name || '';
        field.dataset.fixtureValueDefined = Object.prototype.hasOwnProperty.call(savedValues, name) ? 'true' : 'false';
      });
      this.updateDirtyState();
      this.renderedHtmlPreviewUrl = '';
      this.scheduleRenderedHtmlRefresh();
    } catch (error) {
      this.dispatchComponentFileActionCancelled();
      const payload = typeof error?.resolve === 'function' ? await error.resolve() : null;
      Notification.error('Variant save failed', payload?.message || error?.message || 'The variant values could not be saved.');
    } finally {
      this.updateDirtyState();
    }
  }

  promptCopyVariant() {
    if (this.variantIdentifier === '') {
      return;
    }

    const variantName = this.variantIdentifier.split(':').pop() || '';
    const name = window.prompt('New variant name', `${variantName} copy`);
    if (name === null) {
      return;
    }

    this.copyVariant(name);
  }

  async copyVariant(name) {
    if (this.variantIdentifier === '' || this.copyVariantButton === null) {
      return;
    }

    this.copyVariantButton.disabled = true;
    this.dispatchComponentFileActionStarted('copy');

    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.frontend_studio_component_tree_copy_variant)
        .post({
          identifier: this.variantIdentifier,
          name,
          values: this.collectValues(),
        });
      const payload = await response.resolve();

      if (payload.success !== true || payload.variant === undefined) {
        throw new Error(payload.message || 'The variant could not be copied.');
      }

      Notification.success('Variant copied', 'The variant values were written to a new fixture variant.');
      top.document.dispatchEvent(new CustomEvent('frontend-studio:component-files-changed', {
        detail: {
          variantIdentifier: payload.variant.identifier,
        },
      }));

      const variantUrl = new URL(window.location.href);
      variantUrl.searchParams.set('componentVariant', payload.variant.identifier);
      window.location.href = variantUrl.toString();
    } catch (error) {
      this.dispatchComponentFileActionCancelled('copy');
      const payload = typeof error?.resolve === 'function' ? await error.resolve() : null;
      Notification.error('Variant copy failed', payload?.message || error?.message || 'The variant could not be copied.');
      this.copyVariantButton.disabled = false;
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

  async copyFluidUsageSnippet() {
    if (this.fluidUsageSnippet === '') {
      return;
    }

    try {
      await navigator.clipboard.writeText(this.fluidUsageSnippet);
      Notification.success('Fluid usage copied', this.fluidUsageSnippet);
    } catch (error) {
      Notification.error('Copy failed', error?.message || 'The Fluid usage snippet could not be copied.');
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
