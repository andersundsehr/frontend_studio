import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createContext, SourceTextModule, SyntheticModule } from 'node:vm';
import test from 'node:test';

async function setup() {
  let current = 'https://example.test/typo3/module?componentVariant=site:card:Default&site=preview&language=de-DE';
  const navigations: URL[] = [];
  const persisted: { identifier: string; treeIdentifier: string }[] = [];
  const order: string[] = [];
  let storedState = { identifier: 'site:card:Default', treeIdentifier: 'site_site:card_site:card:Default' };
  const document = new EventTarget();
  document.addEventListener('frontend-studio:before-navigate', () => order.push('confirm'));
  const context = createContext({
    URL, CustomEvent, Event, EventTarget, customElements: { define() {} },
    top: { document, TYPO3: { ModuleMenu: { App: { getCurrentModule: () => 'admin_frontendstudio' } } } },
    window: { location: { origin: 'https://example.test' } },
  });
  const dependencies: Record<string, Record<string, unknown>> = {
    lit: { LitElement: class {}, html() {} },
    '@typo3/backend/tree/tree.js': { Tree: class {} },
    '@typo3/backend/tree/tree-node.js': { TreeNodePositionEnum: {} },
    '@typo3/backend/module.js': { ModuleUtility: { getFromName: () => ({ link: '/typo3/module' }) } },
    '@typo3/backend/viewport.js': { default: { ContentContainer: {
      get: () => ({ location: { href: current } }), getUrl: () => current,
      setUrl: (url: URL) => { current = url.toString(); navigations.push(url); order.push('navigate'); },
    } } },
    '@typo3/backend/enum/severity.js': { SeverityEnum: {} },
    '@typo3/backend/tree/tree-toolbar.js': { TreeToolbar: class {} },
    '@typo3/backend/storage/module-state-storage.js': { ModuleStateStorage: {
      current: () => storedState,
      updateWithTreeIdentifier: (_type: string, identifier: string, treeIdentifier: string) => {
        storedState = { identifier, treeIdentifier };
        persisted.push(storedState);
        order.push('persist');
      },
    } },
  };
  const module = new SourceTextModule(await readFile(new URL('../../Resources/Public/JavaScript/Backend/component-tree-container.js', import.meta.url), 'utf8'), { context });
  await module.link(async (specifier) => {
    const values = dependencies[specifier] ?? { default: class {} };
    const dependency = new SyntheticModule(Object.keys(values), function () {
      for (const [name, value] of Object.entries(values)) this.setExport(name, value);
    }, { context });
    await dependency.link(() => { throw new Error('Unexpected dependency'); });
    return dependency;
  });
  await module.evaluate();
  const Container = (module.namespace as any).FrontendStudioComponentTreeContainer;
  const container = new Container();
  const folder = { nodeType: 'folder', identifier: 'site:folder', __treeIdentifier: 'site_folder', depth: 0, checked: false };
  const nestedFolder = { ...folder, identifier: 'site:folder:nested', __treeIdentifier: 'site_folder_nested', depth: 1 };
  const component = { nodeType: 'component', identifier: 'site:card', __treeIdentifier: 'site_site:card', depth: 2, checked: false };
  const variant = { nodeType: 'variant', identifier: 'site:card:Default', __treeIdentifier: storedState.treeIdentifier, depth: 3, checked: true };
  type Node = typeof component;
  const nodes = [folder, nestedFolder, component, variant];
  container.tree = {
    nodes,
    getSelectedNodes: () => nodes.filter((node) => node.checked),
    resetSelectedNodes: () => nodes.forEach((node) => { node.checked = false; }),
    expandNodeParents: async () => {}, focusNode() {},
    selectNode: (node: Node, propagate = true) => {
      container.tree.resetSelectedNodes();
      node.checked = true;
      return container.loadVariant({ detail: { node, propagate } });
    },
  };
  return {
    container, folder, component, variant, document, navigations, persisted, order,
    select: (node: Node, propagate = true) => container.tree.selectNode(node, propagate),
    stored: () => storedState,
    setCurrent: (url: string) => { current = url; },
    setStored: (node: Node) => { storedState = { identifier: node.identifier, treeIdentifier: node.__treeIdentifier }; },
  };
}

test('component selection opens overview while variant links and site/language remain distinct', async () => {
  const ui = await setup();
  await ui.select(ui.component);
  assert.equal(ui.navigations[0].searchParams.get('component'), 'site:card');
  assert.equal(ui.navigations[0].searchParams.has('componentVariant'), false);
  assert.equal(ui.navigations[0].searchParams.get('site'), 'preview');
  assert.equal(ui.navigations[0].searchParams.get('language'), 'de-DE');
  assert.equal(ui.container.getNodeFromCurrentContentUrl(), ui.component);
  assert.deepEqual(ui.order, ['confirm', 'navigate', 'persist']);
  assert.equal(ui.stored().identifier, ui.component.identifier);
  await ui.select(ui.variant);
  assert.equal(ui.navigations[1].searchParams.get('componentVariant'), 'site:card:Default');
  assert.equal(ui.navigations[1].searchParams.has('component'), false);
  assert.equal(ui.container.getNodeFromCurrentContentUrl(), ui.variant);
  assert.equal(ui.stored().identifier, ui.variant.identifier);
  assert.equal(ui.stored().treeIdentifier, ui.variant.__treeIdentifier);
  ui.setCurrent(ui.navigations[0].toString());
  assert.equal(ui.container.getNodeFromCurrentContentUrl(), ui.component);
});

for (const firstComponentHasVariants of [true, false]) {
  test(`folder selection opens the first nested component overview (has variants: ${firstComponentHasVariants})`, async () => {
    const ui = await setup();
    const laterComponent = { ...ui.component, identifier: 'site:later', __treeIdentifier: 'site_later' };
    const laterVariant = { ...ui.variant, identifier: 'site:later:Default', __treeIdentifier: 'site_later_Default', checked: false };
    if (!firstComponentHasVariants) ui.container.tree.nodes.splice(ui.container.tree.nodes.indexOf(ui.variant), 1);
    ui.container.tree.nodes.push(laterComponent, laterVariant);
    await ui.select(ui.folder);
    assert.equal(ui.container.getSelectedNode(), ui.component);
    assert.equal(ui.navigations.length, 1);
    assert.equal(ui.navigations[0].searchParams.get('component'), ui.component.identifier);
    assert.equal(ui.navigations[0].searchParams.has('componentVariant'), false);
    assert.equal(ui.navigations[0].searchParams.get('site'), 'preview');
    assert.equal(ui.navigations[0].searchParams.get('language'), 'de-DE');
    assert.equal(ui.stored().identifier, ui.component.identifier);
    assert.deepEqual(ui.order, ['confirm', 'navigate', 'persist']);
  });
}

test('cancelled folder navigation restores the open variant and preserves its stored state', async () => {
  const ui = await setup();
  const stored = { ...ui.stored() };
  ui.document.addEventListener('frontend-studio:before-navigate', (event) => event.preventDefault());
  await ui.select(ui.folder);
  assert.equal(ui.container.getSelectedNode(), ui.variant);
  assert.equal(ui.folder.checked, false);
  assert.equal(ui.component.checked, false);
  assert.equal(ui.navigations.length, 0);
  assert.equal(ui.persisted.length, 0);
  assert.deepEqual(ui.stored(), stored);
  assert.deepEqual(ui.order, ['confirm']);
});

test('restoring selection does not persist, confirm or navigate', async () => {
  const ui = await setup();
  await ui.select(ui.component, false);
  assert.equal(ui.navigations.length, 0);
  assert.equal(ui.persisted.length, 0);
  assert.deepEqual(ui.order, []);
});

for (const currentView of ['component', 'variant'] as const) {
  test(`cancelled navigation from a ${currentView} keeps storage and restores the visible selection`, async () => {
    const ui = await setup();
    const previous = ui[currentView];
    const target = currentView === 'component' ? ui.variant : ui.component;
    const parameter = currentView === 'component' ? 'component' : 'componentVariant';
    ui.setCurrent(`https://example.test/typo3/module?${parameter}=${previous.identifier}`);
    ui.setStored(previous);
    const stored = { ...ui.stored() };
    ui.document.addEventListener('frontend-studio:before-navigate', (event) => {
      assert.equal(target.checked, true, 'TYPO3 has already selected the clicked node');
      assert.deepEqual(ui.stored(), stored, 'storage must remain unchanged before confirmation');
      event.preventDefault();
    });
    await ui.select(target);
    assert.equal(ui.navigations.length, 0);
    assert.equal(ui.persisted.length, 0);
    assert.deepEqual(ui.stored(), stored);
    assert.equal(previous.checked, true);
    assert.equal(target.checked, false);
    assert.equal(ui.container.getSelectedNode(), previous);
    assert.equal(ui.container.getNodeFromCurrentContentUrl(), previous);
    assert.deepEqual(ui.order, ['confirm'], 'restoring must not ask again');
  });
}

test('cancelled navigation restores stored selection when the content URL has no matching node', async () => {
  const ui = await setup();
  ui.setCurrent('https://example.test/typo3/module');
  ui.document.addEventListener('frontend-studio:before-navigate', (event) => event.preventDefault());
  await ui.select(ui.component);
  assert.equal(ui.container.getSelectedNode(), ui.variant);
  assert.equal(ui.persisted.length, 0);
  assert.equal(ui.navigations.length, 0);
});

test('cancelled navigation clears selection if no previous node exists', async () => {
  const ui = await setup();
  ui.setCurrent('https://example.test/typo3/module');
  ui.setStored({ ...ui.variant, identifier: 'site:removed', __treeIdentifier: 'removed' });
  ui.document.addEventListener('frontend-studio:before-navigate', (event) => event.preventDefault());
  await ui.select(ui.component);
  assert.equal(ui.container.getSelectedNode(), null);
  assert.equal(ui.persisted.length, 0);
  assert.equal(ui.navigations.length, 0);
});
