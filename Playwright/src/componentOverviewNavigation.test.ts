import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createContext, SourceTextModule, SyntheticModule } from 'node:vm';
import test from 'node:test';

async function setup() {
  let current = 'https://example.test/typo3/module?componentVariant=site:card:Default&site=preview&language=de-DE';
  const navigations: URL[] = [];
  const document = new EventTarget();
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
      setUrl: (url: URL) => { current = url.toString(); navigations.push(url); },
    } } },
    '@typo3/backend/enum/severity.js': { SeverityEnum: {} },
    '@typo3/backend/tree/tree-toolbar.js': { TreeToolbar: class {} },
    '@typo3/backend/storage/module-state-storage.js': { ModuleStateStorage: { updateWithTreeIdentifier() {} } },
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
  const component = { nodeType: 'component', identifier: 'site:card', checked: true };
  const variant = { nodeType: 'variant', identifier: 'site:card:Default', checked: true };
  container.tree = { nodes: [component, variant] };
  return { container, component, variant, document, navigations, setCurrent: (url: string) => { current = url; } };
}

test('component selection opens overview while variant links and site/language remain distinct', async () => {
  const ui = await setup();
  await ui.container.loadVariant({ detail: { node: ui.component } });
  assert.equal(ui.navigations[0].searchParams.get('component'), 'site:card');
  assert.equal(ui.navigations[0].searchParams.has('componentVariant'), false);
  assert.equal(ui.navigations[0].searchParams.get('site'), 'preview');
  assert.equal(ui.navigations[0].searchParams.get('language'), 'de-DE');
  assert.equal(ui.container.getNodeFromCurrentContentUrl(), ui.component);
  await ui.container.loadVariant({ detail: { node: ui.variant } });
  assert.equal(ui.navigations[1].searchParams.get('componentVariant'), 'site:card:Default');
  assert.equal(ui.navigations[1].searchParams.has('component'), false);
  assert.equal(ui.container.getNodeFromCurrentContentUrl(), ui.variant);
  ui.setCurrent(ui.navigations[0].toString());
  assert.equal(ui.container.getNodeFromCurrentContentUrl(), ui.component);
});

test('restoring selection does not navigate and unsaved documentation can cancel a selection', async () => {
  const ui = await setup();
  await ui.container.loadVariant({ detail: { node: ui.component, propagate: false } });
  assert.equal(ui.navigations.length, 0);
  ui.document.addEventListener('frontend-studio:before-navigate', (event) => event.preventDefault());
  await ui.container.loadVariant({ detail: { node: ui.component } });
  assert.equal(ui.navigations.length, 0);
});
