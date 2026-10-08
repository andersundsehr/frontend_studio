import { LitElement, html } from 'lit';
import { Tree } from '@typo3/backend/tree/tree.js';
import { TreeNodePositionEnum } from '@typo3/backend/tree/tree-node.js';
import { ModuleUtility } from '@typo3/backend/module.js';
import Viewport from '@typo3/backend/viewport.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import { SeverityEnum } from '@typo3/backend/enum/severity.js';
import { TreeToolbar } from '@typo3/backend/tree/tree-toolbar.js';
import ClientStorage from '@typo3/backend/storage/client.js';
import { ModuleStateStorage } from '@typo3/backend/storage/module-state-storage.js';
import ComponentFileWatcher from '@andersundsehr/frontend-studio/backend/component-file-watcher.js';

const componentTreeModuleStateType = 'frontend_studio_component_tree';
const frontendStudioModuleName = 'admin_frontendstudio';
const initialExpansionLevel = 10;
const editableNodeSelectionDelay = 200;
const componentFileActionStartedEventName = 'frontend-studio:component-file-action-started';
const componentFileActionCancelledEventName = 'frontend-studio:component-file-action-cancelled';

function createUrl(url, parameters = {}) {
  const resolvedUrl = new URL(url, window.location.origin);

  Object.entries(parameters).forEach(([name, value]) => {
    if (value === null || value === undefined) {
      return;
    }

    resolvedUrl.searchParams.set(name, String(value));
  });

  return resolvedUrl;
}

class FrontendStudioComponentTree extends Tree {
  constructor() {
    super();
    this.allowNodeEdit = true;
    this.settings.defaultProperties = {
      checked: false,
      editable: false,
      hasChildren: false,
      labels: [],
      loaded: true,
      note: '',
      overlayIcon: '',
      prefix: '',
      recordType: 'frontend-studio-component',
      statusInformation: [],
      suffix: '',
      tooltip: '',
    };
    this.pendingNodeSelectionTimeout = null;
    this.filterGeneration = 0;
    this.filterComplete = Promise.resolve();
    this.dataLoadComplete = Promise.resolve();
  }

  disconnectedCallback() {
    this.clearPendingNodeSelection();
    super.disconnectedCallback();
  }

  get currentFilterRequest() {
    return this.pendingFilterRequest ?? null;
  }

  set currentFilterRequest(request) {
    this.pendingFilterRequest = request;
    // Core's void filter() rethrows failures without finishing its pipeline.
    // Report them through its notification API, then let its abort handler
    // finish normally. Configure only this request through Ajax's public API.
    request?.addMiddleware(async (input, next) => {
      try {
        const response = await next(input);
        if (!response.ok) throw new Error(`Tree search failed (${response.status}).`);
        await response.clone().json();
        return response;
      } catch (error) {
        if (!input.signal.aborted && !(error instanceof DOMException && error.name === 'AbortError')) {
          this.errorNotification(error);
        }
        throw new DOMException('Tree search was interrupted.', 'AbortError');
      }
    });
  }

  getNodeStatus(node) {
    return this.getStoredNodeStatus(node) ?? {
      expanded: node.depth < initialExpansionLevel,
    };
  }

  enhanceNodes(nodes) {
    const enhancedNodes = super.enhanceNodes(nodes);
    const rootNodes = enhancedNodes.filter((node) => node.depth === 0);

    if (rootNodes.length === 1) {
      const storedStatus = this.getStoredNodeStatus(rootNodes[0]);
      if (storedStatus !== null) {
        rootNodes[0].__expanded = storedStatus.expanded === true;
      }
    }

    return enhancedNodes;
  }

  getStoredNodeStatus(node) {
    const storedTreeState = JSON.parse(ClientStorage.get(this.getLocalStorageIdentifier()) ?? '{}');

    return storedTreeState[node.__treeIdentifier] ?? null;
  }

  async handleNodeEdit(node, name) {
    if (node.readOnly || node.nodeType !== 'variant') {
      return;
    }

    if (node.identifier.startsWith('NEW')) {
      await this.createVariant(node, name);
      return;
    }

    await this.renameVariant(node, name);
  }

  handleNodeClick(event, node) {
    if (event.detail !== 1) {
      return;
    }

    event.preventDefault();
    event.stopPropagation();
    this.clearPendingNodeSelection();

    if (this.isNodeEditable(node)) {
      this.pendingNodeSelectionTimeout = window.setTimeout(() => {
        this.pendingNodeSelectionTimeout = null;

        if (this.nodes.includes(node) && this.editingNode !== node) {
          this.selectNode(node, true);
        }
      }, editableNodeSelectionDelay);
      return;
    }

    if (this.editingNode !== node) {
      this.selectNode(node, true);
    }
  }

  handleNodeDoubleClick(event, node) {
    event.preventDefault();
    event.stopPropagation();
    this.clearPendingNodeSelection();

    if (this.editingNode !== node) {
      this.editNode(node);
    }
  }

  clearPendingNodeSelection() {
    if (this.pendingNodeSelectionTimeout === null) {
      return;
    }

    window.clearTimeout(this.pendingNodeSelectionTimeout);
    this.pendingNodeSelectionTimeout = null;
  }

  async renameVariant(node, name) {
    if (node.readOnly) {
      return;
    }

    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.frontend_studio_component_tree_rename_variant)
        .post({
          identifier: node.identifier,
          name,
        });
      const payload = await response.resolve();

      if (payload.success !== true || payload.variant === undefined) {
        throw new Error(payload.message || 'The variant could not be renamed.');
      }

      await this.loadData();
      const renamedNode = this.nodes.find((candidate) => candidate.identifier === payload.variant.identifier) ?? null;
      if (renamedNode !== null) {
        await this.expandNodeParents(renamedNode);
        this.selectNode(renamedNode);
        this.focusNode(renamedNode);
        this.scrollNodeIntoViewIfNeeded(renamedNode);
      }
    } catch (error) {
      const payload = typeof error?.resolve === 'function' ? await error.resolve() : null;
      Notification.error('Variant rename failed', payload?.message || error?.message || 'The variant could not be renamed.');
      await this.loadData();
    }
  }

  async createVariant(node, name) {
    this.dispatchComponentFileActionStarted('create', node.componentIdentifier);

    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.frontend_studio_component_tree_create_variant)
        .post({
          identifier: node.componentIdentifier,
          name,
        });
      const payload = await response.resolve();

      if (payload.success !== true || payload.variant === undefined) {
        throw new Error(payload.message || 'The variant could not be created.');
      }

      await this.loadData();
      const createdNode = this.nodes.find((candidate) => candidate.identifier === payload.variant.identifier) ?? null;
      if (createdNode !== null) {
        await this.expandNodeParents(createdNode);
        this.selectNode(createdNode);
        this.focusNode(createdNode);
        this.scrollNodeIntoViewIfNeeded(createdNode);
      }
    } catch (error) {
      this.dispatchComponentFileActionCancelled('create', node.componentIdentifier);
      const payload = typeof error?.resolve === 'function' ? await error.resolve() : null;
      Notification.error('Variant creation failed', payload?.message || error?.message || 'The variant could not be created.');
      await this.loadData();
    }
  }

  async copyVariant(node, name) {
    if (node.readOnly) {
      return;
    }

    this.dispatchComponentFileActionStarted('copy', node.identifier);

    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.frontend_studio_component_tree_copy_variant)
        .post({
          identifier: node.identifier,
          name,
        });
      const payload = await response.resolve();

      if (payload.success !== true || payload.variant === undefined) {
        throw new Error(payload.message || 'The variant could not be copied.');
      }

      await this.loadData();
      const copiedNode = this.nodes.find((candidate) => candidate.identifier === payload.variant.identifier) ?? null;
      if (copiedNode !== null) {
        await this.expandNodeParents(copiedNode);
        this.selectNode(copiedNode);
        this.focusNode(copiedNode);
        this.scrollNodeIntoViewIfNeeded(copiedNode);
      }
    } catch (error) {
      this.dispatchComponentFileActionCancelled('copy', node.identifier);
      const payload = typeof error?.resolve === 'function' ? await error.resolve() : null;
      Notification.error('Variant copy failed', payload?.message || error?.message || 'The variant could not be copied.');
      await this.loadData();
    }
  }

  createNodeContentAction(node) {
    if (node.nodeType === 'component') {
      return html`
        <span class="node-action">
          <button
            type="button"
            class="btn btn-default btn-sm btn-icon btn-borderless"
            title=${node.readOnly ? "Production: component files are read-only" : "Create variant"}
            ?hidden=${node.readOnly}
            @click=${(event) => {
              event.preventDefault();
              event.stopImmediatePropagation();
              this.createVariantNode(node);
            }}
          >
            <typo3-backend-icon identifier="actions-plus" size="small"></typo3-backend-icon>
          </button>
          <button
            type="button"
            class="btn btn-default btn-sm btn-icon btn-borderless"
            title="Download component folder"
            @click=${(event) => {
              event.preventDefault();
              event.stopImmediatePropagation();
              this.downloadComponentFolder(node);
            }}
          >
            <typo3-backend-icon identifier="actions-download" size="small"></typo3-backend-icon>
          </button>
        </span>
      `;
    }

    if (node.nodeType === 'variant' && !node.identifier.startsWith('NEW')) {
      return html`
        <span class="node-action">
          <button
            type="button"
            class="btn btn-default btn-sm btn-icon btn-borderless"
            title=${node.readOnly ? "Production: component files are read-only" : "Copy variant"}
            ?hidden=${node.readOnly}
            @click=${(event) => {
              event.preventDefault();
              event.stopImmediatePropagation();
              this.promptCopyVariant(node);
            }}
          >
            <typo3-backend-icon identifier="actions-edit-copy" size="small"></typo3-backend-icon>
          </button>
          <button
            type="button"
            class="btn btn-default btn-sm btn-icon btn-borderless"
            title=${node.readOnly ? "Production: component files are read-only" : "Delete variant"}
            ?hidden=${node.readOnly}
            @click=${(event) => {
              event.preventDefault();
              event.stopImmediatePropagation();
              this.confirmDeleteVariant(node);
            }}
          >
            <typo3-backend-icon identifier="actions-delete" size="small"></typo3-backend-icon>
          </button>
        </span>
      `;
    }

    return super.createNodeContentAction(node);
  }

  async createVariantNode(componentNode) {
    if (componentNode.readOnly) {
      return;
    }

    const identifier = `NEW:${componentNode.identifier}:${Date.now()}`;

    await this.addNode({
      identifier,
      name: '',
      componentIdentifier: componentNode.identifier,
      editable: true,
      hasChildren: false,
      loaded: true,
      icon: 'actions-bookmark',
      nodeType: 'variant',
      labels: [],
    }, componentNode, TreeNodePositionEnum.INSIDE);

    const createdNode = this.nodes.find((candidate) => candidate.identifier === identifier) ?? null;
    if (createdNode !== null) {
      this.editNode(createdNode);
    }
  }

  async handleNodeAdd() {
    // Variant creation is persisted once the temporary node title is submitted.
  }

  promptCopyVariant(node) {
    const name = window.prompt('New variant name', `${node.name} copy`);
    if (name === null) {
      return;
    }

    this.copyVariant(node, name);
  }

  confirmDeleteVariant(node) {
    const modal = Modal.advanced({
      title: 'Delete variant',
      content: `Do you really want to delete the variant "${node.name}"?`,
      severity: SeverityEnum.warning,
      size: Modal.sizes.small,
      buttons: [
        {
          text: 'Cancel',
          active: true,
          btnClass: 'btn-default',
          name: 'cancel',
          trigger: () => modal.hideModal(),
        },
        {
          text: 'Delete',
          btnClass: 'btn-danger',
          name: 'delete',
          trigger: () => {
            modal.hideModal();
            this.deleteVariant(node);
          },
        },
      ],
    });
  }

  async deleteVariant(node) {
    if (node.readOnly) {
      return;
    }

    this.dispatchComponentFileActionStarted('delete', node.identifier);

    try {
      const response = await new AjaxRequest(TYPO3.settings.ajaxUrls.frontend_studio_component_tree_delete_variant)
        .post({
          identifier: node.identifier,
        });
      const payload = await response.resolve();

      if (payload.success !== true || payload.variant === undefined) {
        throw new Error(payload.message || 'The variant could not be deleted.');
      }

      await this.loadData();
      const componentNode = this.nodes.find((candidate) => candidate.identifier === payload.variant.componentIdentifier) ?? null;
      if (componentNode !== null) {
        await this.expandNodeParents(componentNode);
        this.selectNode(componentNode);
        this.focusNode(componentNode);
        this.scrollNodeIntoViewIfNeeded(componentNode);
      }
    } catch (error) {
      this.dispatchComponentFileActionCancelled('delete', node.identifier);
      const payload = typeof error?.resolve === 'function' ? await error.resolve() : null;
      Notification.error('Variant deletion failed', payload?.message || error?.message || 'The variant could not be deleted.');
      await this.loadData();
    }
  }

  downloadComponentFolder(node) {
    const downloadUrl = createUrl(TYPO3.settings.ajaxUrls.frontend_studio_component_tree_download_component, {
      identifier: node.identifier,
    });
    const downloadLink = document.createElement('a');
    downloadLink.href = downloadUrl.toString();
    downloadLink.download = '';
    downloadLink.rel = 'noopener noreferrer';
    downloadLink.style.display = 'none';
    document.body.append(downloadLink);
    downloadLink.click();
    downloadLink.remove();
  }

  dispatchComponentFileActionStarted(action, identifier) {
    top.document.dispatchEvent(new CustomEvent(componentFileActionStartedEventName, {
      detail: {
        action,
        identifier,
      },
    }));
  }

  dispatchComponentFileActionCancelled(action, identifier) {
    top.document.dispatchEvent(new CustomEvent(componentFileActionCancelledEventName, {
      detail: {
        action,
        identifier,
      },
    }));
  }

  async refreshOrFilterTree() {
    if (this.searchTerm !== null && this.searchTerm !== '') {
      await this.filter(this.searchTerm);
      return;
    }

    await this.loadData();
  }

  filter(searchTerm) {
    const generation = ++this.filterGeneration;
    this.currentFilterRequest?.abort();
    // Core's old request can clear currentFilterRequest while a newer one is
    // running. Serialize its pipelines and skip queries superseded by reset.
    this.filterComplete = this.filterComplete.then(async () => {
      await this.dataLoadComplete;
      if (generation !== this.filterGeneration) return;
      super.filter(searchTerm);
      await this.waitForRefreshToFinish();
    });
    return this.filterComplete;
  }

  loadData() {
    this.dataLoadComplete = super.loadData();
    return this.dataLoadComplete;
  }

  async waitForRefreshToFinish() {
    await this.dataLoadComplete;
    while (this.loading === true || this.currentFilterRequest != null) {
      await new Promise((resolve) => this.addEventListener('frontend-studio:tree:idle', resolve, { once: true }));
    }
  }

  updated(changedProperties) {
    super.updated(changedProperties);
    if (this.loading !== true && this.currentFilterRequest == null) {
      this.dispatchEvent(new Event('frontend-studio:tree:idle'));
    }
  }

  async resetFilterForNavigation() {
    // Core resetFilter() does not cancel or join its filter response pipeline.
    ++this.filterGeneration;
    this.currentFilterRequest?.abort();
    await this.filterComplete;
    await this.waitForRefreshToFinish();
    await this.resetFilter();
    // TYPO3 13's resetFilter() can start a reload without returning its promise.
    await this.waitForRefreshToFinish();
  }
}

customElements.define('andersundsehr-frontend-studio-component-tree', FrontendStudioComponentTree);

class FrontendStudioComponentTreeToolbar extends TreeToolbar {
  collapseAll(event) {
    event.preventDefault();

    this.tree.nodes.forEach((node) => {
      if (node.hasChildren === true && node.__expanded === true) {
        this.tree.hideChildren(node);
      }
    });
  }
}

customElements.define('andersundsehr-frontend-studio-component-tree-toolbar', FrontendStudioComponentTreeToolbar);

class FrontendStudioComponentTreeContainer extends LitElement {
  createRenderRoot() {
    return this;
  }

  connectedCallback() {
    super.connectedCallback();
    this.fileWatcher = new ComponentFileWatcher();
    top.document.addEventListener('typo3-module-loaded', this.restoreTreeStateAfterModuleLoaded);
    top.document.addEventListener('frontend-studio:component-files-changed', this.refreshTreeAfterComponentFilesChanged);
  }

  disconnectedCallback() {
    this.fileWatcher.destroy();
    top.document.removeEventListener('typo3-module-loaded', this.restoreTreeStateAfterModuleLoaded);
    top.document.removeEventListener('frontend-studio:component-files-changed', this.refreshTreeAfterComponentFilesChanged);
    super.disconnectedCallback();
  }

  restoreTreeState = async (event) => {
    this.tree = event.currentTarget;
    this.treeInitialized = true;
    this.connectToolbar();

    await this.restoreTreeStateFromCurrentContext();
  };

  restoreTreeStateAfterModuleLoaded = async (event) => {
    if (this.treeInitialized !== true || event.detail?.module !== frontendStudioModuleName) {
      return;
    }

    await this.restoreTreeStateFromCurrentContext();
  };

  refreshTreeAfterComponentFilesChanged = async (event) => {
    if (event.detail?.ownAction === true || this.treeInitialized !== true || this.tree === null) {
      return;
    }

    const selectedIdentifier = this.getSelectedNode()?.identifier ?? null;
    await this.tree.refreshOrFilterTree();

    const selectedNode = selectedIdentifier !== null
      ? this.tree.nodes.find((candidate) => candidate.identifier === selectedIdentifier) ?? null
      : null;
    if (selectedNode !== null) {
      await this.selectNode(selectedNode, false);
      return;
    }

    await this.restoreTreeStateFromCurrentContext();
  };

  async restoreTreeStateFromCurrentContext() {
    const urlNode = this.getNodeFromCurrentContentUrl();
    if (urlNode !== null) {
      await this.selectNode(urlNode, false);
      return;
    }

    const selectedNode = this.getSelectedNode();
    if (selectedNode !== null) {
      await this.selectNode(selectedNode);
      return;
    }

    const storedState = ModuleStateStorage.current(componentTreeModuleStateType);
    const storedNode = this.getNodeFromStoredState(storedState);

    if (storedNode !== null) {
      await this.selectNode(storedNode);
    }
  }

  loadVariant = async (event) => {
    const { node, propagate } = event.detail;

    if (node?.checked !== true) {
      return;
    }

    if (propagate === false) {
      return;
    }

    if (node.nodeType !== 'variant' && node.nodeType !== 'component') {
      const firstChildComponent = this.findFirstChildNodeOfType(node, 'component');

      if (firstChildComponent !== null) {
        await this.selectNode(firstChildComponent);
      }

      return;
    }

    await this.navigateToNode(node);
  };

  async navigateToNode(node, navigation = null) {
    const alreadyConfirmed = navigation !== null;
    navigation ??= new CustomEvent('frontend-studio:before-navigate', { cancelable: true, detail: { url: null } });
    let navigated = false;
    try {
      const moduleMenu = top.TYPO3.ModuleMenu.App;
      const moduleConfiguration = ModuleUtility.getFromName(moduleMenu.getCurrentModule());
      const currentContentUrl = Viewport.ContentContainer.get()?.location?.href || Viewport.ContentContainer.getUrl();
      const currentQueryParams = currentContentUrl !== null
        ? new URL(currentContentUrl, window.location.origin).searchParams
        : null;
      const contentParameters = {
        [node.nodeType === 'component' ? 'component' : 'componentVariant']: node.identifier,
      };
      ['site', 'language'].forEach((parameter) => {
        const value = currentQueryParams?.get(parameter);
        if (value !== null && value !== undefined) {
          contentParameters[parameter] = value;
        }
      });
      const contentUrl = createUrl(moduleConfiguration.link, contentParameters);
      contentUrl.searchParams.delete(node.nodeType === 'component' ? 'componentVariant' : 'component');

      if (navigation.defaultPrevented || (!alreadyConfirmed && !top.document.dispatchEvent(navigation))) {
        return false;
      }

      navigation.detail.url = contentUrl.href;
      await Viewport.ContentContainer.setUrl(contentUrl);
      navigated = true;
      ModuleStateStorage.updateWithTreeIdentifier(componentTreeModuleStateType, node.identifier, node.__treeIdentifier);
      return true;
    } catch (error) {
      Notification.error('Variant navigation failed', error?.message || 'The variant could not be opened.');
      return false;
    } finally {
      if (!navigated) {
        navigation.preventDefault();
        if (!alreadyConfirmed) await this.restoreSelection();
      }
    }
  }

  async restoreSelection() {
    const previousNode = this.getNodeFromCurrentContentUrl()
      ?? this.getNodeFromStoredState(ModuleStateStorage.current(componentTreeModuleStateType));
    if (previousNode !== null) {
      await this.selectNode(previousNode, false);
    } else {
      this.tree.resetSelectedNodes();
    }
  }

  async selectVariant(identifier) {
    if (!this.tree || !identifier) return;
    let node = this.tree.nodes.find((candidate) => candidate.nodeType === 'variant' && candidate.identifier === identifier);
    if (!node) {
      // Confirm before changing the filter; a cancelled Docs link must leave the tree untouched.
      const navigation = new CustomEvent('frontend-studio:before-navigate', { cancelable: true, detail: { url: null } });
      if (!top.document.dispatchEvent(navigation)) return;
      let navigated = false;
      try {
        await this.tree.resetFilterForNavigation();
        node = this.tree.nodes.find((candidate) => candidate.nodeType === 'variant' && candidate.identifier === identifier);
        if (node && !navigation.defaultPrevented) {
          await this.selectNode(node, false);
          navigated = await this.navigateToNode(node, navigation);
        }
      } catch (error) {
        Notification.error('Variant navigation failed', error?.message || 'The variant could not be opened.');
      } finally {
        const searchInput = this.toolbar?.querySelector(this.toolbar.settings.searchInput);
        if (searchInput) searchInput.value = this.tree.searchTerm ?? '';
        if (!navigated) {
          navigation.preventDefault();
          await this.restoreSelection();
        }
      }
      return;
    }
    if (node) await this.selectNode(node);
  }

  async selectNode(node, propagate = true) {
    await this.tree.expandNodeParents(node);
    this.tree.selectNode(node, propagate);
    this.tree.focusNode(node);
    if (typeof this.tree.scrollNodeIntoViewIfNeeded === 'function') {
      // only present in TYPO3 >=14
      this.tree.scrollNodeIntoViewIfNeeded(node);
    }
  }

  getNodeFromCurrentContentUrl() {
    const contentUrl = Viewport.ContentContainer.getUrl();
    const componentVariant = contentUrl !== null
      ? (new URL(contentUrl, window.location.origin).searchParams.get('component')
        || new URL(contentUrl, window.location.origin).searchParams.get('componentVariant'))
      : null;

    if (componentVariant === null || componentVariant === '') {
      return null;
    }

    return this.tree.nodes.find((candidate) => candidate.identifier === componentVariant) ?? null;
  }

  getSelectedNode() {
    return this.tree.getSelectedNodes()[0] ?? null;
  }

  getNodeFromStoredState(storedState) {
    if (!storedState.identifier) {
      return null;
    }

    return this.tree.nodes.find((candidate) => (
      storedState.treeIdentifier !== null
        ? candidate.__treeIdentifier === storedState.treeIdentifier
        : candidate.identifier === storedState.identifier
    )) ?? null;
  }

  connectToolbar() {
    this.toolbar = this.querySelector('andersundsehr-frontend-studio-component-tree-toolbar');

    if (this.toolbar !== null) {
      this.toolbar.tree = this.tree;
    }
  }

  findFirstChildNodeOfType(node, nodeType) {
    const nodeIndex = this.tree.nodes.indexOf(node);

    if (nodeIndex === -1) {
      return null;
    }

    for (let index = nodeIndex + 1; index < this.tree.nodes.length; index++) {
      const candidate = this.tree.nodes[index];

      if (candidate.depth <= node.depth) {
        break;
      }

      if (candidate.nodeType === nodeType) {
        return candidate;
      }
    }

    return null;
  }

  render() {
    return html`
      <style>
        andersundsehr-frontend-studio-component-tree-container,
        andersundsehr-frontend-studio-component-tree {
          display: flex;
          flex-direction: column;
          height: 100%;
          min-height: 0;
        }

        andersundsehr-frontend-studio-component-tree-container > :last-child,
        andersundsehr-frontend-studio-component-tree > :last-child {
          flex: 1;
          min-height: 0;
        }

        andersundsehr-frontend-studio-component-tree .node-action {
          gap: 2px;
          width: auto;
        }
      </style>
      <andersundsehr-frontend-studio-component-tree-toolbar
        .tree=${this.tree}
        id="frontend-studio-component-tree-toolbar"
      ></andersundsehr-frontend-studio-component-tree-toolbar>
      <andersundsehr-frontend-studio-component-tree
        id="frontend-studio-component-tree"
        .setup=${{
          id: 'frontend-studio-component-tree',
          dataUrl: TYPO3.settings.ajaxUrls.frontend_studio_component_tree_data,
          filterUrl: TYPO3.settings.ajaxUrls.frontend_studio_component_tree_filter,
          searchPlaceholder: 'Search components',
          showIcons: true,
        }}
        @tree:initialized=${this.restoreTreeState}
        @typo3:tree:node-selected=${this.loadVariant}
      ></andersundsehr-frontend-studio-component-tree>
    `;
  }

  firstUpdated() {
    this.tree = this.querySelector('andersundsehr-frontend-studio-component-tree');
    this.connectToolbar();
  }
}

customElements.define('andersundsehr-frontend-studio-component-tree-container', FrontendStudioComponentTreeContainer);

const navigationComponentName = 'andersundsehr-frontend-studio-component-tree-container';

export {
  FrontendStudioComponentTree,
  FrontendStudioComponentTreeContainer,
  navigationComponentName,
};
