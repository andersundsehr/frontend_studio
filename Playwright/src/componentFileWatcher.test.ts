import assert from 'node:assert/strict';
import { test } from 'node:test';
import { backendModules } from './backendModules.ts';

async function setup() {
  const document = new EventTarget();
  const top = Object.assign(new EventTarget(), { document });
  const window = new EventTarget();
  const streams: EventSource[] = [];
  class EventSource extends EventTarget {
    closed = false;
    uri: string;
    constructor(uri: string) { super(); this.uri = uri; streams.push(this); }
    close() { this.closed = true; }
  }
  const modules = backendModules({ top, window, EventSource });
  const { default: Watcher } = await modules.import('component-file-watcher.js');
  const watcher = new Watcher();
  const moduleLoaded = (module = 'admin_frontendstudio', uri: string | undefined = '/changes') => {
    document.dispatchEvent(new CustomEvent('typo3-module-loaded', { detail: { module, componentChangeStreamUri: uri } }));
  };
  const change = (data = JSON.stringify({ componentIdentifiers: ['site:card'] }), source = streams.at(-1)!) => {
    source.dispatchEvent(Object.assign(new Event('component-files-changed'), { data }));
  };
  return { document, top, window, streams, watcher, moduleLoaded, change };
}

test('one backend watcher survives repeated variant loads and stops when the module is inactive', async (t) => {
  const env = await setup();
  t.after(() => env.watcher.destroy());
  env.moduleLoaded();
  for (let index = 0; index < 30; index++) {
    env.moduleLoaded();
    env.window.dispatchEvent(new Event('pagehide'));
  }
  assert.equal(env.streams.length, 1);
  assert.equal(env.streams[0].closed, false);
  env.document.dispatchEvent(new CustomEvent('typo3-module-load', { detail: { module: 'web_layout' } }));
  assert.equal(env.streams[0].closed, true);
  env.moduleLoaded('web_layout');
  assert.equal(env.streams.length, 1);
  env.moduleLoaded();
  assert.equal(env.streams.length, 2);
  env.moduleLoaded('admin_frontendstudio', '');
  assert.equal(env.streams[1].closed, true, 'production views must stop watching');
  assert.equal(env.streams.length, 2);
  env.watcher.destroy();
  env.moduleLoaded();
  assert.equal(env.streams.length, 2, 'removing the owning tree removes its listeners');
});

test('cached backend restoration reconnects once and normal teardown never reconnects', async (t) => {
  const env = await setup();
  t.after(() => env.watcher.destroy());
  env.moduleLoaded();
  env.top.dispatchEvent(Object.assign(new Event('pagehide'), { persisted: true }));
  assert.equal(env.streams[0].closed, true);
  env.moduleLoaded();
  assert.equal(env.streams.length, 1);
  env.top.dispatchEvent(Object.assign(new Event('pageshow'), { persisted: true }));
  env.top.dispatchEvent(Object.assign(new Event('pageshow'), { persisted: true }));
  assert.equal(env.streams.length, 2);
  env.top.dispatchEvent(new Event('pagehide'));
  assert.equal(env.streams[1].closed, true);
  env.top.dispatchEvent(Object.assign(new Event('pageshow'), { persisted: true }));
  assert.equal(env.streams.length, 2);
});

test('shared events retain component identifiers and suppress successful own file actions', async (t) => {
  const env = await setup();
  t.after(() => env.watcher.destroy());
  env.moduleLoaded();
  const events: any[] = [];
  env.document.addEventListener('frontend-studio:component-files-changed', (event) => events.push((event as CustomEvent).detail));
  env.change('invalid JSON');
  env.change('null');
  env.change('{}');
  assert.equal(events.length, 0);
  for (const action of ['save', 'copy', 'create', 'transformer']) {
    env.document.dispatchEvent(new CustomEvent('frontend-studio:component-file-action-started', { detail: { action, variantIdentifier: 'site:card:Default' } }));
    env.change();
    assert.equal(events.at(-1).ownAction, true);
  }
  env.document.dispatchEvent(new CustomEvent('frontend-studio:component-file-action-started', { detail: { variantIdentifier: 'site:card:Default' } }));
  env.document.dispatchEvent(new CustomEvent('frontend-studio:component-file-action-cancelled', { detail: { variantIdentifier: 'site:card:Default' } }));
  env.change();
  assert.equal(events.at(-1).ownAction, false);
  assert.deepEqual(Array.from(events.at(-1).componentIdentifiers), ['site:card']);
  env.moduleLoaded('web_layout');
  env.change(undefined, env.streams[0]);
  assert.equal(events.length, 5, 'a closed connection cannot dispatch late changes');
});

for (const action of ['create', 'copy', 'delete', 'save']) {
  test(`${action} retains colon variant actions through unrelated SSE and cancels by full identifier`, async (t) => {
    const env = await setup();
    t.after(() => env.watcher.destroy());
    env.moduleLoaded();
    const events: any[] = [];
    env.document.addEventListener('frontend-studio:component-files-changed', (event) => events.push((event as CustomEvent).detail));
    const identifier = action === 'create' ? 'site:card' : 'site:card:Mobile:Dark';
    const detail = action === 'save' ? { action, variantIdentifier: identifier } : { action, identifier };
    const dispatch = (type: string, payload = detail) => env.document.dispatchEvent(new CustomEvent(
      `frontend-studio:component-file-action-${type}`, { detail: payload },
    ));
    dispatch('started');
    env.change(JSON.stringify({ componentIdentifiers: ['site:cardOther'] }));
    assert.equal(events.at(-1).ownAction, false);
    env.change();
    assert.equal(events.at(-1).ownAction, true);
    assert.deepEqual(Array.from(events.at(-1).ownActionIdentifiers), action === 'save' ? [] : [identifier]);
    env.change();
    assert.equal(events.at(-1).ownAction, false);
    dispatch('started');
    dispatch('cancelled');
    env.change();
    assert.equal(events.at(-1).ownAction, false);
  });
}

test('documentation-only SSE updates are forwarded separately and do not consume a pending fixture write', async (t) => {
  const env = await setup(); t.after(() => env.watcher.destroy());
  env.moduleLoaded();
  const documents: string[][] = [];
  const files: any[] = [];
  env.document.addEventListener('frontend-studio:component-documentation-changed', event => {
    documents.push(Array.from((event as CustomEvent).detail.componentIdentifiers));
  });
  env.document.addEventListener('frontend-studio:component-files-changed', event => files.push((event as CustomEvent).detail));
  env.document.dispatchEvent(new CustomEvent('frontend-studio:component-file-action-started', {
    detail: { variantIdentifier: 'site:card:Default' },
  }));
  env.change(JSON.stringify({ componentIdentifiers: [], documentationComponentIdentifiers: ['site:card'] }));
  assert.deepEqual(documents, [['site:card']]);
  assert.equal(files.length, 0, 'Markdown changes must not trigger page/tree reloads');
  env.change();
  assert.equal(files[0].ownAction, true, 'the matching fixture event still belongs to our write');
  env.change(JSON.stringify({ componentIdentifiers: ['site:other'], documentationComponentIdentifiers: ['site:card'] }));
  assert.deepEqual(documents, [['site:card'], ['site:card']]);
  assert.deepEqual(Array.from(files[1].componentIdentifiers), ['site:other']);
});
