import assert from 'node:assert/strict';
import { test } from 'node:test';
import { backendModules } from './backendModules.ts';

const widthKey = 'frontendStudio.variantView.sidebarWidth';
const heightKey = 'frontendStudio.variantView.sidebarHeight';

function pointerEvent(type: string, pointerId: number, clientX = 800, clientY = 500): Event {
  return Object.assign(new Event(type, { cancelable: true }), { button: 0, pointerId, clientX, clientY });
}

async function createSidebar({ stacked = true, height = 220, previewUri = '', iframe = false } = {}) {
  let direction = stacked ? 'column' : 'row';
  let capturedPointerId: number | null = null;
  const attributes = new Map<string, string>();
  const properties = new Map([
    ['--frontend-studio-variant-sidebar-width', '420px'],
    ['--frontend-studio-variant-sidebar-height', `${height}px`],
  ]);
  const classes = new Set<string>();
  const persisted: [string, number][] = [];
  const workspace = {
    getBoundingClientRect: () => ({ width: 1200, height: 800, right: 1200, bottom: 800 }),
  };
  const handle = Object.assign(new EventTarget(), {
    getBoundingClientRect: () => ({ height: 8 }),
    setAttribute: (name: string, value: string) => attributes.set(name, value),
    setPointerCapture: (pointerId: number) => { capturedPointerId = pointerId; },
    hasPointerCapture: (pointerId: number) => capturedPointerId === pointerId,
    releasePointerCapture(pointerId: number): void {
      capturedPointerId = null;
      handle.dispatchEvent(pointerEvent('lostpointercapture', pointerId));
    },
  });
  const elements = new Map<string, unknown>([
    ['.frontend-studio-variant-workspace', workspace],
    ['.frontend-studio-variant-sidebar', {}],
    ['[data-frontend-studio-variant-sidebar-resize]', handle],
    ['.frontend-studio-variant-header', { getBoundingClientRect: () => ({ height: 60 }) }],
    ['[data-frontend-studio-variant-frame]', iframe ? {} : null],
  ]);
  const root = {
    dataset: { sidebarHeight: String(height), previewUri, variantIdentifier: 'site:card:Default' },
    querySelector: (selector: string) => elements.get(selector) ?? null,
    querySelectorAll: () => [],
    style: { setProperty: (name: string, value: string) => properties.set(name, value) },
    classList: { add: (name: string) => classes.add(name), remove: (name: string) => classes.delete(name) },
  };
  const window = Object.assign(new EventTarget(), { innerWidth: 1200, innerHeight: 800 });
  const { default: View } = await backendModules({
    window,
    getComputedStyle: (element: unknown) => ({
      flexDirection: element === workspace ? direction : '',
      getPropertyValue: (name: string) => element === root ? properties.get(name) ?? '' : '',
    }),
  }, {
    '@typo3/backend/storage/persistent.js': { set: async (key: string, value: number) => { persisted.push([key, value]); } },
  }).import('variant-sidebar-resize.js');
  const view = new View(root);
  view.initialize();

  return {
    view, handle, attributes, properties, classes, persisted,
    changeLayout(stacked: boolean) {
      direction = stacked ? 'column' : 'row';
      window.dispatchEvent(new Event('resize'));
    },
  };
}

for (const config of [
  { previewUri: '', iframe: false },
  { previewUri: '', iframe: true },
  { previewUri: '/__frontendStudio/preview', iframe: false },
]) {
  test(`resizes without a usable preview (${JSON.stringify(config)}) and restores height 0`, async () => {
    const sidebar = await createSidebar({ ...config, height: 0 });
    assert.equal(sidebar.attributes.get('aria-orientation'), 'horizontal');
    assert.equal(sidebar.properties.get('--frontend-studio-variant-sidebar-height'), '160px');
    assert.equal(sidebar.attributes.get('aria-valuenow'), '160');

    sidebar.handle.dispatchEvent(pointerEvent('pointerdown', 1));
    assert.equal(sidebar.handle.hasPointerCapture(1), true);
    assert.equal(sidebar.classes.has('is-resizing-sidebar'), true);
    sidebar.handle.dispatchEvent(pointerEvent('pointermove', 1, 800, 450));
    sidebar.handle.dispatchEvent(pointerEvent('pointerup', 1));

    assert.equal(sidebar.properties.get('--frontend-studio-variant-sidebar-height'), '350px');
    assert.equal(sidebar.properties.get('--frontend-studio-variant-sidebar-width'), '420px');
    assert.equal(sidebar.handle.hasPointerCapture(1), false);
    assert.equal(sidebar.classes.has('is-resizing-sidebar'), false);
    assert.deepEqual(sidebar.persisted, [[heightKey, 350]]);
  });
}

test('desktop resizing persists width independently of the stored height', async () => {
  const sidebar = await createSidebar({ stacked: false });
  assert.equal(sidebar.attributes.get('aria-orientation'), 'vertical');
  sidebar.handle.dispatchEvent(pointerEvent('pointerdown', 1));
  sidebar.handle.dispatchEvent(pointerEvent('pointermove', 1, 760));
  sidebar.handle.dispatchEvent(pointerEvent('pointerup', 1));

  assert.equal(sidebar.properties.get('--frontend-studio-variant-sidebar-width'), '440px');
  assert.equal(sidebar.properties.get('--frontend-studio-variant-sidebar-height'), '220px');
  assert.deepEqual(sidebar.persisted, [[widthKey, 440]]);
});

test('switching layout during a resize finishes and persists the old dimension', async () => {
  const sidebar = await createSidebar({ stacked: false });
  sidebar.handle.dispatchEvent(pointerEvent('pointerdown', 1, 740));
  sidebar.changeLayout(true);

  assert.deepEqual(sidebar.persisted, [[widthKey, 460]]);
  assert.equal(sidebar.view.isResizingSidebar, false);
  assert.equal(sidebar.handle.hasPointerCapture(1), false);
  assert.equal(sidebar.attributes.get('aria-orientation'), 'horizontal');
  assert.equal(sidebar.properties.get('--frontend-studio-variant-sidebar-height'), '220px');
  sidebar.handle.dispatchEvent(pointerEvent('pointermove', 1, 800, 450));
  assert.equal(sidebar.properties.get('--frontend-studio-variant-sidebar-height'), '220px');

  sidebar.handle.dispatchEvent(pointerEvent('pointerdown', 2, 800, 530));
  sidebar.handle.dispatchEvent(pointerEvent('pointerup', 2));
  assert.deepEqual(sidebar.persisted, [[widthKey, 460], [heightKey, 270]]);
});

test('lost pointer capture finishes once and events from another pointer are ignored', async () => {
  const sidebar = await createSidebar();
  sidebar.handle.dispatchEvent(pointerEvent('pointerdown', 7, 800, 450));
  sidebar.handle.dispatchEvent(pointerEvent('pointermove', 8, 800, 300));
  sidebar.handle.dispatchEvent(pointerEvent('lostpointercapture', 8));
  sidebar.handle.dispatchEvent(pointerEvent('pointerup', 8));
  assert.equal(sidebar.view.isResizingSidebar, true);
  assert.equal(sidebar.properties.get('--frontend-studio-variant-sidebar-height'), '350px');
  assert.deepEqual(sidebar.persisted, []);

  sidebar.handle.releasePointerCapture(7);
  sidebar.handle.dispatchEvent(pointerEvent('pointerup', 7));
  assert.equal(sidebar.view.isResizingSidebar, false);
  assert.equal(sidebar.classes.has('is-resizing-sidebar'), false);
  assert.deepEqual(sidebar.persisted, [[heightKey, 350]]);
});

for (const stacked of [false, true]) {
  test(`resize clamps its dimension and updates ARIA bounds (stacked=${stacked})`, async (t) => {
    const sidebar = await createSidebar({ stacked });
    t.after(() => sidebar.view.destroy());
    const key = stacked ? '--frontend-studio-variant-sidebar-height' : '--frontend-studio-variant-sidebar-width';
    const minimum = stacked ? 160 : 280;
    const maximum = stacked ? 572 : 840;
    sidebar.handle.dispatchEvent(pointerEvent('pointerdown', 1, 5000, 5000));
    assert.equal(sidebar.properties.get(key), `${minimum}px`);
    assert.equal(sidebar.attributes.get('aria-valuenow'), String(minimum));
    sidebar.handle.dispatchEvent(pointerEvent('pointermove', 1, -5000, -5000));
    assert.equal(sidebar.properties.get(key), `${maximum}px`);
    assert.equal(sidebar.attributes.get('aria-valuemin'), String(minimum));
    assert.equal(sidebar.attributes.get('aria-valuemax'), String(maximum));
    assert.equal(sidebar.attributes.get('aria-valuenow'), String(maximum));
    sidebar.handle.dispatchEvent(pointerEvent('pointercancel', 1));
    assert.deepEqual(sidebar.persisted, [[stacked ? heightKey : widthKey, maximum]]);
    assert.equal(sidebar.view.isResizingSidebar, false);
    assert.equal(sidebar.handle.hasPointerCapture(1), false);
  });
}

test('destroying an active resize releases capture, persists once and removes pointer listeners', async () => {
  const sidebar = await createSidebar();
  sidebar.handle.dispatchEvent(pointerEvent('pointerdown', 1, 800, 450));
  sidebar.view.destroy();
  assert.equal(sidebar.view.isResizingSidebar, false);
  assert.equal(sidebar.handle.hasPointerCapture(1), false);
  assert.equal(sidebar.classes.has('is-resizing-sidebar'), false);
  assert.deepEqual(sidebar.persisted, [[heightKey, 350]]);
  sidebar.handle.dispatchEvent(pointerEvent('pointerdown', 2, 800, 300));
  sidebar.handle.dispatchEvent(pointerEvent('pointermove', 2, 800, 200));
  sidebar.handle.dispatchEvent(pointerEvent('pointerup', 2));
  assert.equal(sidebar.properties.get('--frontend-studio-variant-sidebar-height'), '350px');
  assert.equal(sidebar.persisted.length, 1);
});
