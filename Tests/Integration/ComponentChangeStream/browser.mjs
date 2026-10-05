import assert from 'node:assert/strict';
import { createServer, request as httpRequest } from 'node:http';
import { readFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { chromium } from '../../../Playwright/node_modules/playwright-core/index.mjs';

const apache = `http://127.0.0.1:${process.argv[2]}`;
const backend = new URL('../../../Resources/Public/JavaScript/Backend/', import.meta.url);
const tree = 'andersundsehr-frontend-studio-component-tree-container';
const prefix = '@andersundsehr/frontend-studio/backend/';
const imports = { [prefix]: '/backend/', lit: '/stub/lit' };
for (const name of [
  'backend/tree/tree.js', 'backend/tree/tree-node.js', 'backend/module.js', 'backend/viewport.js',
  'core/ajax/ajax-request.js', 'backend/modal.js', 'backend/notification.js', 'backend/enum/severity.js',
  'backend/tree/tree-toolbar.js', 'backend/storage/client.js', 'backend/storage/module-state-storage.js', 'core/document-service.js',
]) imports[`@typo3/${name}`] = `/stub/${name}`;
const head = `<script type="importmap">${JSON.stringify({ imports })}</script>`;
let streamRequests = 0;
let moduleRequests = 0;
const errors = [];
const server = createServer(async (request, response) => {
  const url = new URL(request.url, 'http://localhost');
  const send = (type, body) => { response.writeHead(200, { 'Content-Type': type, 'Cache-Control': 'public, max-age=3600' }); response.end(body); };
  try {
    if (url.pathname === '/stream.php' || url.pathname === '/health.php') {
      if (url.pathname === '/stream.php') streamRequests++;
      const proxy = httpRequest(apache + url.pathname, (upstream) => { response.writeHead(upstream.statusCode, upstream.headers); upstream.pipe(response); });
      proxy.on('error', (error) => { if (!response.destroyed) { response.writeHead(502); response.end(error.message); } });
      response.on('close', () => proxy.destroy());
      proxy.end();
      return;
    }
    if (url.pathname === '/other') return send('text/html', '<title>Other</title>Other');
    if (url.pathname === '/') return send('text/html', `${head}<script>
      window.currentModule='admin_frontendstudio';window.fileEvents=0;window.treeRefreshes=0;
      window.TYPO3={ModuleMenu:{App:{getCurrentModule:()=>window.currentModule}},Backend:{NavigationContainer:{showComponent:()=>{}}}};
      document.addEventListener('frontend-studio:component-files-changed',()=>window.fileEvents++);
      window.pageShows=[];addEventListener('pageshow',e=>window.pageShows.push(e.persisted));
      </script><script type="module">
      await import('${prefix}component-tree-container.js');
      const container=document.createElement('${tree}');document.body.append(container);
      container.tree={nodes:[],getSelectedNodes:()=>[],refreshOrFilterTree:async()=>window.treeRefreshes++};container.treeInitialized=true;
      const frame=document.createElement('iframe');frame.id='content';frame.src='/variant?componentVariant=site:card:Default';document.body.append(frame);
      window.ready=true;
      </script><body></body>`);
    if (url.pathname === '/variant') {
      moduleRequests++;
      const health = await fetch(apache + '/health.php');
      assert.equal(health.status, 200);
      const identifier = url.searchParams.get('componentVariant') || 'site:card:Default';
      return send('text/html', `${head}<script>window.TYPO3={settings:{ajaxUrls:{frontend_studio_component_tree_update_variant_values:'/save'}}};</script>
        <main data-frontend-studio-variant-view data-variant-identifier="${identifier}" data-component-change-stream-uri="/stream.php">
        <div data-frontend-studio-variant-controls><input data-frontend-studio-variant-value data-fixture-name="title" data-fixture-type="string" data-fixture-value-defined="true" value="Saved">
        <button data-frontend-studio-variant-save>Save</button><button data-frontend-studio-variant-reset>Reset</button></div></main>
        <script type="module">import '${prefix}variant-view.js';import '${prefix}variant-controls.js';import '${prefix}component-tree-startup.js';</script>`);
    }
    if (url.pathname === '/save') return send('application/json', JSON.stringify({ success: true, variant: {} }));
    if (url.pathname.startsWith('/backend/')) return send('text/javascript', await readFile(new URL(url.pathname.slice(9), backend), 'utf8'));
    if (url.pathname === '/stub/lit') return send('text/javascript', 'export class LitElement extends HTMLElement {connectedCallback(){}disconnectedCallback(){}} export const html=()=>"";');
    if (url.pathname === '/stub/backend/tree/tree.js') return send('text/javascript', 'export class Tree extends HTMLElement {}');
    if (url.pathname === '/stub/backend/tree/tree-toolbar.js') return send('text/javascript', 'export class TreeToolbar extends HTMLElement {}');
    if (url.pathname === '/stub/backend/tree/tree-node.js') return send('text/javascript', 'export const TreeNodePositionEnum={};');
    if (url.pathname === '/stub/backend/module.js') return send('text/javascript', 'export const ModuleUtility={getFromName:()=>({link:"/variant"})};');
    if (url.pathname === '/stub/backend/viewport.js') return send('text/javascript', 'export default {ContentContainer:{getUrl:()=>document.querySelector("#content")?.src??null}};');
    if (url.pathname === '/stub/backend/enum/severity.js') return send('text/javascript', 'export const SeverityEnum={};');
    if (url.pathname === '/stub/backend/storage/module-state-storage.js') return send('text/javascript', 'export const ModuleStateStorage={current:()=>({}),updateWithTreeIdentifier:()=>{}};');
    if (url.pathname === '/stub/core/document-service.js') return send('text/javascript', 'export default {ready:()=>document.readyState==="loading"?new Promise(r=>document.addEventListener("DOMContentLoaded",r,{once:true})):Promise.resolve()};');
    if (url.pathname === '/stub/core/ajax/ajax-request.js') return send('text/javascript', 'export default class {async post(){return {resolve:async()=>({success:true,variant:{}})};}}');
    if (url.pathname === '/stub/backend/notification.js') return send('text/javascript', 'export default {success:()=>{},error:()=>{}};');
    if (url.pathname.startsWith('/stub/')) return send('text/javascript', 'export default {};');
    response.writeHead(404); response.end();
  } catch (error) { response.writeHead(500); response.end(String(error)); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const origin = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch({ executablePath: process.env.CHROMIUM_PATH || (existsSync('/usr/bin/chromium') ? '/usr/bin/chromium' : chromium.executablePath()), args: ['--no-sandbox'], ignoreDefaultArgs: ['--disable-back-forward-cache'] });
const status = async () => (await fetch(apache + '/status.php?json')).json();
const waitForIdle = async () => {
  for (let index = 0; index < 80; index++) {
    if ((await status())['active processes'] === 0) return;
    await new Promise(resolve => setTimeout(resolve, 100));
  }
  assert.fail('PHP workers did not return to idle after disconnect');
};
try {
  for (const cacheDisabled of [false, true]) {
    const page = await browser.newPage();
    page.on('pageerror', error => errors.push(error.message));
    const cdp = await page.context().newCDPSession(page);
    await cdp.send('Network.enable');
    await cdp.send('Network.setCacheDisabled', { cacheDisabled });
    const before = streamRequests;
    await page.goto(origin);
    await page.waitForFunction(() => document.querySelector('andersundsehr-frontend-studio-component-tree-container')?.fileWatcher?.source?.readyState === 1);
    for (let index = 0; index < 25; index++) {
      await page.evaluate(index => { document.querySelector('#content').src = '/variant?componentVariant=site:card:Variant' + index; }, index);
      await page.frameLocator('#content').locator('input').waitFor();
      if (index % 5 === 0) {
        const start = performance.now();
        assert.equal((await fetch(apache + '/health.php')).status, 200);
        assert.ok(performance.now() - start < 1000, 'independent health request must stay responsive');
        assert.equal((await status())['active processes'], 1);
      }
    }
    await page.waitForFunction(() => document.querySelector('#content').contentDocument.querySelector('[data-frontend-studio-variant-view]')?.dataset.variantIdentifier === 'site:card:Variant24');
    assert.equal(streamRequests - before, 1, 'variant navigation must retain one SSE request');
    const frame = page.frames().find(frame => frame.url().includes('/variant'));
    await frame.locator('input').fill('Unsaved');
    const beforeDocuments = moduleRequests;
    await page.evaluate(() => {
      const owner = document.querySelector('andersundsehr-frontend-studio-component-tree-container').fileWatcher;
      owner.handleFilesChanged({data:'{"componentIdentifiers":["site:other"]}'});
      owner.handleFilesChanged({data:'{"componentIdentifiers":["site:card"]}'});
    });
    assert.equal(await frame.locator('input').inputValue(), 'Unsaved');
    assert.equal(moduleRequests, beforeDocuments);
    assert.equal(await page.evaluate(() => window.treeRefreshes), 2);
    await frame.locator('[data-frontend-studio-variant-save]').click();
    await frame.locator('[data-frontend-studio-variant-save]').waitFor({state:'visible'});
    await page.waitForFunction(() => document.querySelector('#content').contentDocument.querySelector('[data-frontend-studio-variant-save]').disabled);
    await page.evaluate(() => document.querySelector('andersundsehr-frontend-studio-component-tree-container').fileWatcher.handleFilesChanged({data:'{"componentIdentifiers":["site:card"]}'}));
    assert.equal(moduleRequests, beforeDocuments, 'own save change must be suppressed');
    assert.equal(await page.evaluate(() => window.treeRefreshes), 2, 'own save must not refresh the tree twice');
    await page.evaluate(() => document.querySelector('andersundsehr-frontend-studio-component-tree-container').fileWatcher.handleFilesChanged({data:'{"componentIdentifiers":["site:card"]}'}));
    await page.waitForFunction(() => document.querySelector('#content').contentDocument.querySelector('input')?.value === 'Saved');
    assert.equal(moduleRequests, beforeDocuments + 1, 'external changes must reload a clean affected variant');
    assert.equal(streamRequests - before, 1, 'a file-triggered reload must also retain the stream');
    await page.evaluate(() => {window.currentModule='web_layout';document.dispatchEvent(new CustomEvent('typo3-module-loaded',{detail:{module:'web_layout'}}));});
    await waitForIdle();
    console.log(`Passed 25 variant switches (cache ${cacheDisabled ? 'disabled' : 'enabled'}): one SSE, one occupied worker, independent health <1s, dirty and save behavior, inactive module releases both workers.`);
    await page.close();
  }
  const cached = await browser.newPage();
  cached.on('pageerror', error => errors.push(error.message));
  await cached.goto(origin);
  await cached.waitForFunction(() => document.querySelector('andersundsehr-frontend-studio-component-tree-container')?.fileWatcher?.source?.readyState === 1);
  await cached.evaluate(() => {location.href='/other';});
  await cached.waitForURL(origin + '/other');
  await waitForIdle();
  const before = streamRequests;
  await cached.goBack({ waitUntil: 'commit' });
  await cached.waitForFunction(() => window.pageShows?.at(-1) === true);
  await cached.waitForFunction(() => document.querySelector('andersundsehr-frontend-studio-component-tree-container')?.fileWatcher?.source?.readyState === 1);
  assert.equal(streamRequests - before, 1);
  // Playwright's frame handles can become stale on a real BFCache restoration.
  await cached.evaluate(() => {
    const child=document.querySelector('#content').contentDocument;
    const input=child.querySelector('input');
    input.value='After restoration';input.dispatchEvent(new Event('input',{bubbles:true}));
    child.querySelector('[data-frontend-studio-variant-reset]').click();
  });
  assert.equal(await cached.evaluate(()=>document.querySelector('#content').contentDocument.querySelector('input').value), 'Saved');
  await cached.evaluate(() => document.querySelector('andersundsehr-frontend-studio-component-tree-container').remove());
  await waitForIdle();
  await cached.close();
  assert.deepEqual(errors, []);
  console.log('Passed real Chromium BFCache restoration, controls and owner removal. Actual extension modules/EventSource and Apache/FPM; TYPO3 shell and snapshots substituted.');
} finally {
  await browser.close();
  server.closeAllConnections();
  await new Promise(resolve => server.close(resolve));
}
