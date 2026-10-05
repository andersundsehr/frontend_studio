import PersistentStorage from '@typo3/backend/storage/persistent.js';
import VariantFeature from '@andersundsehr/frontend-studio/backend/variant-lifecycle.js';

export default class VariantSidebarResize extends VariantFeature {
  static sidebarWidthStorageKey = 'frontendStudio.variantView.sidebarWidth';

  static sidebarHeightStorageKey = 'frontendStudio.variantView.sidebarHeight';

  static defaultSidebarWidth = 360;

  static minimumSidebarWidth = 280;

  static minimumPreviewWidth = 320;

  static minimumSidebarHeight = 160;

  static minimumPreviewHeight = 160;


  constructor(root, view) {
    super(root, view);
    this.workspace = root.querySelector('.frontend-studio-variant-workspace');
    this.sidebar = root.querySelector('.frontend-studio-variant-sidebar');
    this.sidebarResizeHandle = root.querySelector('[data-frontend-studio-variant-sidebar-resize]');
    this.sidebarWidth = VariantSidebarResize.defaultSidebarWidth;
    this.sidebarHeight = Number.parseInt(root.dataset.sidebarHeight || window.innerHeight * 0.45, 10);
    this.isSidebarStacked = false;
    this.isResizingSidebar = false;
    this.sidebarResizePointerId = null;
  }

  initialize() {
    this.initializeSidebarResize();
  }

  destroy() {
    this.finishSidebarResize(this.sidebarResizePointerId);
    super.destroy();
  }

  initializeSidebarResize() {
    if (this.workspace === null || this.sidebar === null || this.sidebarResizeHandle === null) {
      return;
    }

    this.sidebarWidth = this.readInitialSidebarWidth();
    this.updateSidebarLayout();
    this.listen(window, 'resize', () => this.updateSidebarLayout());

    this.listen(this.sidebarResizeHandle, 'pointerdown', (event) => {
      if (event.button !== 0 || this.isResizingSidebar) {
        return;
      }

      event.preventDefault();
      this.isResizingSidebar = true;
      this.sidebarResizePointerId = event.pointerId;
      this.root.classList.add('is-resizing-sidebar');
      this.sidebarResizeHandle.setPointerCapture(event.pointerId);
      this.updateSidebarSizeFromPointer(event);
    });

    this.listen(this.sidebarResizeHandle, 'pointermove', (event) => {
      if (!this.isResizingSidebar || event.pointerId !== this.sidebarResizePointerId) {
        return;
      }

      event.preventDefault();
      this.updateSidebarSizeFromPointer(event);
    });

    this.listen(this.sidebarResizeHandle, 'pointerup', (event) => {
      this.finishSidebarResize(event.pointerId);
    });

    this.listen(this.sidebarResizeHandle, 'pointercancel', (event) => {
      this.finishSidebarResize(event.pointerId);
    });

    this.listen(this.sidebarResizeHandle, 'lostpointercapture', (event) => {
      this.finishSidebarResize(event.pointerId);
    });
  }

  updateSidebarLayout() {
    const isSidebarStacked = getComputedStyle(this.workspace).flexDirection === 'column';
    if (isSidebarStacked !== this.isSidebarStacked) {
      this.finishSidebarResize(this.sidebarResizePointerId);
      this.isSidebarStacked = isSidebarStacked;
    }

    this.sidebarResizeHandle.setAttribute('aria-orientation', isSidebarStacked ? 'horizontal' : 'vertical');
    if (isSidebarStacked) {
      this.applySidebarHeight(this.sidebarHeight);
    } else {
      this.applySidebarWidth(this.sidebarWidth);
    }
  }

  readInitialSidebarWidth() {
    const initialWidth = Number.parseInt(
      getComputedStyle(this.root).getPropertyValue('--frontend-studio-variant-sidebar-width'),
      10,
    );

    if (Number.isFinite(initialWidth)) {
      return initialWidth;
    }

    return VariantSidebarResize.defaultSidebarWidth;
  }

  updateSidebarSizeFromPointer(event) {
    if (this.workspace === null) {
      return;
    }

    const workspaceRect = this.workspace.getBoundingClientRect();
    if (this.isSidebarStacked) {
      this.sidebarHeight = this.applySidebarHeight(workspaceRect.bottom - event.clientY);
    } else {
      this.sidebarWidth = this.applySidebarWidth(workspaceRect.right - event.clientX);
    }
  }

  applySidebarWidth(width) {
    if (this.workspace === null) {
      return;
    }

    const workspaceWidth = this.workspace.getBoundingClientRect().width;
    const maximumSidebarWidth = Math.max(
      VariantSidebarResize.minimumSidebarWidth,
      Math.min(workspaceWidth - VariantSidebarResize.minimumPreviewWidth, window.innerWidth * 0.7),
    );
    const clampedWidth = Math.min(
      Math.max(width, VariantSidebarResize.minimumSidebarWidth),
      maximumSidebarWidth,
    );

    this.root.style.setProperty('--frontend-studio-variant-sidebar-width', `${clampedWidth}px`);
    this.sidebarResizeHandle?.setAttribute('aria-valuemin', String(VariantSidebarResize.minimumSidebarWidth));
    this.sidebarResizeHandle?.setAttribute('aria-valuemax', String(Math.round(maximumSidebarWidth)));
    this.sidebarResizeHandle?.setAttribute('aria-valuenow', String(Math.round(clampedWidth)));

    return clampedWidth;
  }

  applySidebarHeight(height) {
    if (this.workspace === null || this.sidebarResizeHandle === null) {
      return;
    }

    const maximumSidebarHeight = Math.max(
      0,
      this.workspace.getBoundingClientRect().height
        - this.sidebarResizeHandle.getBoundingClientRect().height
        - (this.root.querySelector('.frontend-studio-variant-header')?.getBoundingClientRect().height ?? 0)
        - VariantSidebarResize.minimumPreviewHeight,
    );
    const minimumSidebarHeight = Math.min(VariantSidebarResize.minimumSidebarHeight, maximumSidebarHeight);
    const clampedHeight = Math.min(Math.max(height, minimumSidebarHeight), maximumSidebarHeight);

    this.root.style.setProperty('--frontend-studio-variant-sidebar-height', `${clampedHeight}px`);
    this.sidebarResizeHandle.setAttribute('aria-valuemin', String(Math.round(minimumSidebarHeight)));
    this.sidebarResizeHandle.setAttribute('aria-valuemax', String(Math.round(maximumSidebarHeight)));
    this.sidebarResizeHandle.setAttribute('aria-valuenow', String(Math.round(clampedHeight)));

    return clampedHeight;
  }

  finishSidebarResize(pointerId) {
    if (!this.isResizingSidebar || pointerId !== this.sidebarResizePointerId) {
      return;
    }

    this.isResizingSidebar = false;
    this.sidebarResizePointerId = null;
    this.root.classList.remove('is-resizing-sidebar');

    if (this.sidebarResizeHandle?.hasPointerCapture(pointerId)) {
      this.sidebarResizeHandle.releasePointerCapture(pointerId);
    }

    this.persistSidebarSize();
  }

  async persistSidebarSize() {
    try {
      await PersistentStorage.set(
        this.isSidebarStacked ? VariantSidebarResize.sidebarHeightStorageKey : VariantSidebarResize.sidebarWidthStorageKey,
        Math.round(this.isSidebarStacked ? this.sidebarHeight : this.sidebarWidth),
      );
    } catch {
      // Ignore persistence failures; resizing still works for the current page load.
    }
  }
}
