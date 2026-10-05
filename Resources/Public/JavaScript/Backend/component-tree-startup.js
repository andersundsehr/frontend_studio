const frontendStudioModuleName = 'admin_frontendstudio';
const componentTreeNavigationComponent = '@andersundsehr/frontend-studio/backend/component-tree-container';
const componentTreeContainerSelector = 'andersundsehr-frontend-studio-component-tree-container';
const maximumInitializationAttempts = 20;
const initializationRetryDelay = 100;
const fallbackInitializationAttempt = 5;

class FrontendStudioComponentTreeStartup {
  constructor() {
    this.attempt = 0;
    this.retryTimeout = null;
    window.addEventListener('pagehide', () => window.clearTimeout(this.retryTimeout));
    window.addEventListener('pageshow', (event) => {
      if (event.persisted) {
        this.attempt = 0;
        this.initialize();
      }
    });
  }

  initialize() {
    if (document.querySelector('[data-frontend-studio-variant-view]') === null) {
      return;
    }

    this.synchronizeTopLevelNavigation();
  }

  synchronizeTopLevelNavigation() {
    this.attempt += 1;

    const topWindow = this.getTopWindow();
    const topDocument = topWindow?.document ?? null;
    const moduleMenu = topWindow?.TYPO3?.ModuleMenu?.App ?? null;
    const navigationContainer = topWindow?.TYPO3?.Backend?.NavigationContainer ?? null;

    if (topWindow === null || topDocument === null || moduleMenu === null || navigationContainer === null) {
      this.retryIfPossible();
      return;
    }

    this.dispatchModuleLoadedEvent(topWindow, topDocument);

    if (moduleMenu.getCurrentModule() === frontendStudioModuleName) {
      if (!this.isComponentTreeMounted(topDocument)) {
        if (this.attempt >= fallbackInitializationAttempt) {
          navigationContainer.showComponent(componentTreeNavigationComponent);
        }
        this.retryIfPossible();
      }
      return;
    }

    if (!this.isComponentTreeMounted(topDocument)) {
      navigationContainer.showComponent(componentTreeNavigationComponent);
    }

    if (!this.isComponentTreeMounted(topDocument)) {
      this.retryIfPossible();
    }
  }

  getTopWindow() {
    try {
      return window.top ?? null;
    } catch {
      return null;
    }
  }

  dispatchModuleLoadedEvent(topWindow, topDocument) {
    topDocument.dispatchEvent(new topWindow.CustomEvent('typo3-module-loaded', {
      bubbles: true,
      composed: true,
      detail: {
        module: frontendStudioModuleName,
        title: document.title,
        url: window.location.href,
        componentChangeStreamUri: document.querySelector('[data-frontend-studio-variant-view]')?.dataset.componentChangeStreamUri || '',
      },
    }));
  }

  isComponentTreeMounted(topDocument) {
    return Array.from(topDocument.querySelectorAll('[data-component]')).some((element) => (
      element.dataset.component === componentTreeNavigationComponent
    )) || topDocument.querySelector(componentTreeContainerSelector) !== null;
  }

  retryIfPossible() {
    if (this.attempt >= maximumInitializationAttempts) {
      return;
    }

    this.retryTimeout = window.setTimeout(() => this.synchronizeTopLevelNavigation(), initializationRetryDelay);
  }

  static initialize() {
    new FrontendStudioComponentTreeStartup().initialize();
  }
}

export default FrontendStudioComponentTreeStartup;

FrontendStudioComponentTreeStartup.initialize();
