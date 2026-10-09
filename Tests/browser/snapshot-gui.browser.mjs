import { chromium } from '@playwright/test';
import { fileURLToPath } from 'node:url';
import { readFile, mkdir } from 'node:fs/promises';
import { createServer } from 'node:http';
import path from 'node:path';
import assert from 'node:assert/strict';
import { typo3ReleaseModule, typo3ReleaseResource } from './helpers/typo3Release.mjs';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const typo3Version='14.3.7';
const evidence=process.env.SNAPSHOT_EVIDENCE_DIR || path.join(root, 'var/snapshot-gui-evidence');
const imports={'@andersundsehr/frontend-studio/backend/':'/Resources/Public/JavaScript/Backend/','@typo3/backend/':'/typo3/backend/','@typo3/core/':'/typo3/core/','~labels/':'/labels/','bootstrap':'/typo3/backend/Contrib/bootstrap.js'};
for(const name of ['lit','lit-html','lit-element','@lit/reactive-element']) {
 const entry=name==='lit-html'?'lit-html.js':name==='@lit/reactive-element'?'reactive-element.js':'index.js';
 imports[name]=`/typo3/core/Contrib/${name}/${entry}`;
 imports[`${name}/`]=`/typo3/core/Contrib/${name}/`;
}
const stubs={
 '@typo3/backend/viewport.js':`export default {ContentContainer:{get:()=>window,getUrl:()=>location.href,setUrl:(url)=>{window.selections++;window.openedComponentUrl=String(url)}}};`,
 '@typo3/backend/module.js':`export const ModuleUtility={getFromName:()=>({link:'/module'})};`,
 '@typo3/backend/storage/module-state-storage.js':`export const ModuleStateStorage={current:()=>({}),updateWithTreeIdentifier:()=>{}};`,
 '@typo3/backend/storage/client.js':`export default {get:()=>null,set:()=>{},unsetByPrefix:()=>{},unset:()=>{}};`,
 '@typo3/backend/storage/persistent.js':`export default {get:()=>null,set:async()=>{}};`,
 '@typo3/backend/notification.js':`export default {error:console.error,success:()=>{}};`,
 '@typo3/backend/element/icon-element.js':`customElements.define('typo3-backend-icon',class extends HTMLElement{});`,
 '@typo3/backend/page-wizard/helper/wizard-helper.js':`export default {}; export const WizardHelper={}; export const openPageWizardModal=()=>{};`,
};
for(const key in stubs) imports[key]='/stubs/'+encodeURIComponent(key);
const nodes=[['site:','site:',0,'namespace'],['site:nested.','Nested',1,'folder'],['site:nested.card','Card',2,'component'],['site:nested.card:Default','Default',3,'variant'],['site:nested.card:Second','Second',3,'variant']].map(([identifier,name,depth,nodeType])=>({identifier,name,depth,nodeType,hasChildren:nodeType!=='variant',editable:nodeType==='variant',loaded:true,icon:'actions-code',labels:[]}));
const ids=nodes.filter(n=>n.nodeType==='variant').map(n=>n.identifier);
const requests=[];
const updated=new Set();
const server=createServer(async(req,res)=>{
 try{
 const url=new URL(req.url,'http://localhost');
 if(url.pathname==='/favicon.ico'){res.statusCode=204;res.end();return;}
 if(url.pathname.startsWith('/labels/')) {res.setHeader('Content-Type','text/javascript');res.end('export default new Proxy({get:(key)=>key}, {get:(target,key)=>target[key]??String(key)});');return;}
 if(url.pathname.startsWith('/stubs/')){res.setHeader('Content-Type','text/javascript');res.end(stubs[decodeURIComponent(url.pathname.slice(7))]);return;}
 if(url.pathname.startsWith('/typo3/')){
  const [,,extension,...parts]=url.pathname.split('/');
  const stylesheet=parts[0]==='Css';
  res.setHeader('Content-Type',stylesheet?'text/css':'text/javascript');
  res.end(await (stylesheet?typo3ReleaseResource(typo3Version,extension,parts.join('/')):typo3ReleaseModule(typo3Version,extension,parts.join('/'))));return;
 }
 if(url.pathname==='/tree'){res.setHeader('Content-Type','application/json');res.end(JSON.stringify(nodes));return;}
 if(url.pathname==='/snapshots'){
 let body='';for await(const chunk of req)body+=chunk;
 let data;if(req.headers['content-type']?.startsWith('multipart/')){data=Object.fromEntries(await new Response(body,{headers:{'Content-Type':req.headers['content-type']}}).formData());}else{try{data=JSON.parse(body);}catch{data=Object.fromEntries(new URLSearchParams(body));}}
 requests.push(data);res.setHeader('Content-Type','application/json');
 if(data.operation==='discover'){res.end(JSON.stringify({success:true,catalog:ids,identifiers:ids.filter(id=>!data.scope||id===data.scope||id.startsWith(data.scope+(data.scope==='site:nested.card'?':':'')))}));return;}
 if(data.operation==='update'){
  updated.add(data.scope);
  res.end(JSON.stringify({success:true,result:{identifier:data.scope,status:'updated',message:'Updated snapshot. Review and commit the changes.',path:'Card.html-snapshots/html-Second@preview@de.snapshot.html',expected:'<article>New title</article>',actual:'<article>New title</article>',diff:[]}}));return;
 }
 if(updated.has(data.scope)){res.end(JSON.stringify({success:true,result:{identifier:data.scope,status:'passed',message:'',expected:'',actual:'',diff:[]}}));return;}
 await new Promise(r=>setTimeout(r,80));res.end(JSON.stringify({success:true,result:{identifier:data.scope,status:data.scope.endsWith('Default')?'passed':'failed',message:'Rendered HTML differs from the saved baseline.',path:'Card.html-snapshots/html-Second@preview@de.snapshot.html',diff:Array.from({length:7},(_,index)=>({expected:index+1,actual:index+1,expectedSegments:[{text:['<section>','  <article>','    <h2>Before</h2>','    Old title','    <p>After</p>','  </article>','</section>'][index],changed:index===3}],actualSegments:[{text:['<section>','  <article>','    <h2>Before</h2>','    <img src=x onerror="window.injected=true">','    <p>After</p>','  </article>','</section>'][index],changed:index===3}],changed:index===3})),expected:'<section>\n  <article>\n    <h2>Before</h2>\n    Old title\n    <p>After</p>\n  </article>\n</section>',actual:'<section>\n  <article>\n    <h2>Before</h2>\n    <img src=x onerror="window.injected=true">\n    <p>After</p>\n  </article>\n</section>' }}));return;
 }
 if(url.pathname==='/'||url.pathname==='/module'){
 res.setHeader('Content-Type','text/html');res.end(`<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="/typo3/backend/Css/backend.css"><script type="importmap">${JSON.stringify({imports})}</script><style>body{padding:20px;background:#f4f4f4}.demo-tree{width:min(440px,100%);height:620px;background:white;border:1px solid #aaa}h1{font-size:24px}</style></head><body><h1>Frontend Studio · HTML tests</h1><div data-frontend-studio-variant-header data-selected-site-identifier="preview" data-selected-language-hreflang="de">Preview / German</div><div class="demo-tree"><andersundsehr-frontend-studio-component-tree-container></andersundsehr-frontend-studio-component-tree-container></div><script>window.selections=0;window.TYPO3={settings:{frontendStudio:{snapshotTestingEnabled:${url.searchParams.get('gui')!=='off'},snapshotReadOnly:${url.searchParams.has('production')}},ajaxUrls:{frontend_studio_component_tree_data:'/tree',frontend_studio_component_tree_filter:'/tree',frontend_studio_snapshot:'/snapshots'},ContextHelp:{},formEngine:{}},lang:{},ModuleMenu:{App:{getCurrentModule:()=> 'admin_frontendstudio'}},Backend:{NavigationContainer:{}}};</script><script type="module">await import('@andersundsehr/frontend-studio/backend/component-tree-container.js');window.ready=true;</script></body></html>`);return;
 }
 const file=path.resolve(root,'.'+url.pathname);if(!file.startsWith(root+'/'))throw Error('path');
 res.setHeader('Content-Type',file.endsWith('.js')?'text/javascript':file.endsWith('.css')?'text/css':'application/octet-stream');res.end(await readFile(file));
 }catch(e){res.statusCode=404;res.end(String(e));}
});
await new Promise(r=>server.listen(0,'127.0.0.1',r));
const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH || '/usr/bin/chromium',args:['--no-sandbox']});
try{
 const page=await browser.newPage({viewport:{width:1000,height:780}});
 page.setDefaultTimeout(6000);
 page.on('pageerror',e=>console.error('PAGE ERROR',e.stack||e.message));
 page.on('console',m=>{if(m.type()==='error')console.error('CONSOLE',m.text())});
 await page.goto(`http://127.0.0.1:${server.address().port}/`);
 await page.waitForFunction(()=>window.ready, null, {timeout:10000});
 const row=(name)=>page.locator('.node').filter({has:page.getByText(name,{exact:true})});
 const actions=(name)=>row(name).locator('.node-action > button:not(.snapshot-status)');
 const resultsButton=(count)=>page.getByRole('button',{name:`✕ Results (${count})`,exact:true});
 const assertFailureButton=async(button)=>{
  assert.equal(await button.isVisible(),true);
  const border=await button.evaluate(button=>{
   const style=getComputedStyle(button);
   return {color:style.borderTopColor,style:style.borderTopStyle,width:parseFloat(style.borderTopWidth)};
  });
  assert.notEqual(border.color,'rgba(0, 0, 0, 0)');
  assert.equal(border.style,'solid');
  assert.ok(border.width>0);
 };
 await row('Default').waitFor();
 assert.equal(await actions('Default').evaluateAll(buttons=>buttons.every(button=>getComputedStyle(button).display==='none')),true);
 await row('Default').hover();
 assert.equal(await actions('Default').evaluateAll(buttons=>buttons.every(button=>getComputedStyle(button).display!=='none')),true);
 let releaseFirstRun;
 const firstRunGate=new Promise(resolve=>{releaseFirstRun=resolve;});
 const holdFirstRun=async(route)=>{
  if(!(route.request().postData()||'').includes('discover')) await firstRunGate;
  await route.continue();
 };
 await page.route('**/snapshots',holdFirstRun);
 await page.getByRole('button',{name:'Test Default',exact:true}).click();
 await page.waitForFunction(()=>{
  const tests=document.querySelector('andersundsehr-frontend-studio-component-tree').snapshots;
  return tests.active&&tests.total===1;
 });
 assert.equal(await page.getByRole('status').textContent(),'0/2 passed');
 assert.equal(await page.getByRole('button',{name:'Test all',exact:true}).isDisabled(),true);
 releaseFirstRun();
 await page.getByRole('status').filter({hasText:'1/2 passed'}).waitFor();
 await page.unroute('**/snapshots',holdFirstRun);
 assert.equal(await page.getByRole('button',{name:/✕ Results/}).count(),0);
 await page.mouse.move(980,10);
 await page.getByRole('button',{name:'Test all',exact:true}).focus();
 assert.equal(await page.getByRole('img',{name:'All tests passed'}).count(),1);
 assert.equal(await row('Default').getByRole('img',{name:'All tests passed'}).isVisible(),true);
 assert.equal(await actions('Default').evaluateAll(buttons=>buttons.every(button=>getComputedStyle(button).display==='none')),true);
 await row('Nested').hover();
 await page.getByRole('button',{name:'Test Nested',exact:true}).click();
 await page.mouse.move(980,10);
 await page.getByRole('button',{name:'Test all',exact:true}).focus();
 await page.getByRole('button',{name:'Inspect snapshot failures for Nested',exact:true}).waitFor();
 await assertFailureButton(page.getByRole('button',{name:'Inspect snapshot failures for Nested',exact:true}));
 await assertFailureButton(resultsButton(1));
 assert.equal(await actions('Nested').evaluateAll(buttons=>buttons.every(button=>getComputedStyle(button).display==='none')),true);
 await page.evaluate(()=>{const tree=document.querySelector('andersundsehr-frontend-studio-component-tree');tree.hideChildren(tree.nodes.find(node=>node.nodeType==='folder'));});
 await page.getByRole('button',{name:'Test all',exact:true}).click();
 await page.getByRole('status').filter({hasText:'1/2 passed'}).waitFor();
 await page.waitForFunction(()=>!document.querySelector('andersundsehr-frontend-studio-component-tree').snapshots.active);
 await page.evaluate(()=>{const tree=document.querySelector('andersundsehr-frontend-studio-component-tree');tree.showChildren(tree.nodes.find(node=>node.nodeType==='folder'));});
 assert.equal(await page.evaluate(()=>window.selections),0);
 await mkdir(evidence,{recursive:true});
 await page.mouse.move(980,10);
 await page.screenshot({path:path.join(evidence,'tree.png')});
 await page.getByRole('button',{name:'Inspect snapshot failures for Card',exact:true}).click();
 await page.getByText('Snapshot mismatch: site:nested.card:Second',{exact:true}).waitFor();
 await page.getByRole('heading',{name:'Snapshot mismatch',exact:true}).waitFor();
 const details=page.getByRole('region',{name:'Failure details',exact:true});
 assert.deepEqual(await details.locator('.fs-snapshot-context dt').allTextContents(),['Site','Language']);
 assert.deepEqual(await details.locator('.fs-snapshot-context dd').allTextContents(),['preview','de']);
 await details.getByRole('heading',{name:'Next step',exact:true}).waitFor();
 await details.getByRole('region',{name:'HTML changes',exact:true}).waitFor();
 assert.equal(await details.getByRole('region',{name:'Snapshot file',exact:true}).count(),0);
 await details.getByRole('button',{name:'Copy Snapshot file location',exact:true}).waitFor();
 assert.deepEqual(await details.locator('thead th').allTextContents(),['','Should','Got','HTML']);
 assert.deepEqual(await details.locator('tbody tr:not(.fs-snapshot-line-removed):not(.fs-snapshot-line-added) td:last-child code').allTextContents(),['  <article>','    <h2>Before</h2>','    <p>After</p>','  </article>']);
 const nextStep=details.getByRole('complementary',{name:'Next step',exact:true});
 assert.equal(await nextStep.evaluate(el=>el.closest('[aria-label="HTML changes"]')!==null),true);
 assert.equal(await nextStep.evaluate(el=>getComputedStyle(el).backgroundColor),'rgba(0, 0, 0, 0)');
 assert.equal(await details.getByText('− snapshot · + current output · highlighted words changed. Dynamic values are masked using the snapshot rules.',{exact:true}).count(),0);
 const iconColor=await details.locator('.fs-snapshot-state').evaluate(el=>getComputedStyle(el).color);
 const dangerColor=await details.locator('.fs-snapshot-line-removed .text-danger').evaluate(el=>getComputedStyle(el).color);
 assert.equal(iconColor,dangerColor);
 assert.equal(await details.getByRole('button',{name:'Update snapshot',exact:true}).isEnabled(),true);
 assert.equal(await details.evaluate(details=>{
  const summary=details.querySelector('.fs-snapshot-summary');
  const context=details.querySelector('.fs-snapshot-context');
  const actions=details.querySelector('.fs-snapshot-actions');
  const next=details.querySelector('.fs-snapshot-next');
  return summary.compareDocumentPosition(context)&Node.DOCUMENT_POSITION_FOLLOWING
   &&context.compareDocumentPosition(actions)&Node.DOCUMENT_POSITION_FOLLOWING
   &&actions.compareDocumentPosition(next)&Node.DOCUMENT_POSITION_FOLLOWING;
 }),4);
 const summaryColor=await details.locator('.fs-snapshot-summary').evaluate(el=>getComputedStyle(el).backgroundColor);
 assert.notEqual(summaryColor,'rgba(0, 0, 0, 0)');
 await page.waitForTimeout(350);
 assert.equal(await page.evaluate(()=>window.injected),undefined);
 assert.equal(await page.locator('.fs-snapshot-added').count(),1);
 assert.equal(await page.locator('details[open]').count(),0);
 assert.equal(await details.getByText('Card.html-snapshots/html-Second@preview@de.snapshot.html',{exact:true}).count(),0);
 assert.equal(await page.getByText('Technical details',{exact:true}).count(),0);
 await page.setViewportSize({width:1000,height:1150});
 await page.locator('.t3js-modal-body').evaluate(body=>{body.scrollTop=0;});
 await page.screenshot({path:path.join(evidence,'diff.png')});
 await page.context().grantPermissions(['clipboard-read','clipboard-write']);
 await page.getByRole('button',{name:'Copy Snapshot file location',exact:true}).click();
 await page.getByText('Snapshot file location copied.',{exact:true}).waitFor();
 assert.equal(await page.evaluate(()=>navigator.clipboard.readText()),'Card.html-snapshots/html-Second@preview@de.snapshot.html');
 await page.getByRole('button',{name:'Copy details',exact:true}).click();
 await page.getByText('Details copied.',{exact:true}).waitFor();
 assert.match(await page.evaluate(()=>navigator.clipboard.readText()),/Site: preview · Language: de/);
 await page.setViewportSize({width:1000,height:780});
 await page.getByRole('button',{name:'Close',exact:true}).last().click();
 await page.getByRole('button',{name:'Inspect snapshot failures for Card',exact:true}).focus();
 await page.keyboard.press('Enter');
 await page.getByText('Snapshot mismatch: site:nested.card:Second',{exact:true}).waitFor();
 await page.getByRole('heading',{name:'Snapshot mismatch',exact:true}).waitFor();
 await page.waitForTimeout(350);
 await page.keyboard.press('Escape');
 await page.setViewportSize({width:390,height:780});
 await page.screenshot({path:path.join(evidence,'narrow.png')});
 await page.getByRole('button',{name:'Inspect snapshot failures for Card',exact:true}).click();
 await page.getByRole('heading',{name:'Snapshot mismatch',exact:true}).waitFor();
 await page.waitForTimeout(350);
 await details.getByRole('region',{name:'HTML changes',exact:true}).scrollIntoViewIfNeeded();
 await page.screenshot({path:path.join(evidence,'narrow-diff.png')});
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
 await page.keyboard.press('Escape');
 assert.equal(await page.evaluate(()=>window.selections),0);
 assert.equal(requests.filter(r=>r.operation==='run').length,5);
 // Exercise mixed failures and result navigation without issuing more AJAX runs.
 await page.evaluate(()=>{
  const tests=document.querySelector('andersundsehr-frontend-studio-component-tree').snapshots;
  tests.results.set('site:nested.card:Default',{identifier:'site:nested.card:Default',status:'missing',message:'Snapshot is missing. Create it explicitly outside Production.',path:'Card.html-snapshots/html-Default@preview@de.snapshot.html',expected:'',actual:'<p>New</p>'});
  const identifier='site:nested.card:Broken';
  tests.catalog.push(identifier);
  tests.results.set(identifier,{identifier,status:'error',message:'Transformer failed: <script>bad()</script>',trace:'#0 <img src=x onerror=bad()>',expected:'',actual:''});
  tests.changed();
 });
 await resultsButton(3).click();
 const navigation=page.getByRole('navigation',{name:'Failed variants'});
 await navigation.getByRole('button',{name:/site:nested.card:Default/}).click();
 await page.getByRole('heading',{name:'Missing snapshot',exact:true}).waitFor();
 await page.getByText('Use Create snapshot outside Production, review and commit the file, then rerun the test.',{exact:true}).waitFor();
 assert.equal(await page.locator('.fs-snapshot-diff').count(),0);
 assert.equal(await details.getByRole('button',{name:'Create snapshot',exact:true}).isEnabled(),true);
 assert.equal(requests.filter(r=>r.operation==='update').length,0,'Viewing a missing snapshot must not create it.');
 await page.screenshot({path:path.join(evidence,'missing.png')});
 let creationAttempts=0;
 const createSnapshot=async(route)=>{
  const body=route.request().postData()||'';
  if(!body.includes('update')||!body.includes('Default')){await route.continue();return;}
  creationAttempts++;
  await route.fulfill({json:{success:true,result:{identifier:ids[0],status:creationAttempts===1?'error':'created',message:creationAttempts===1?'Cannot create baseline.':'Created snapshot. Review and commit it before rerunning.',path:'Card.html-snapshots/html-Default@preview@de.snapshot.html',expected:creationAttempts===1?'':'<p>New</p>',actual:'<p>New</p>',diff:[]}}});
 };
 await page.route('**/snapshots',createSnapshot);
 await details.getByRole('button',{name:'Create snapshot',exact:true}).click();
 await details.getByRole('alert',{name:'Snapshot update error',exact:true}).getByText('Cannot create baseline.',{exact:true}).waitFor();
 await page.getByRole('heading',{name:'Missing snapshot',exact:true}).waitFor();
 assert.equal(await details.getByRole('button',{name:'Create snapshot',exact:true}).isEnabled(),true);
 await details.getByRole('button',{name:'Create snapshot',exact:true}).click();
 await page.getByRole('heading',{name:'Snapshot created',exact:true}).waitFor();
 assert.equal(await details.getByRole('button',{name:'Update snapshot',exact:true}).isDisabled(),true);
 assert.match(await details.locator('.fs-snapshot-actions .text-warning[role="status"]').textContent(),/Snapshot created/);
 assert.equal(await page.evaluate(()=>document.querySelector('andersundsehr-frontend-studio-component-tree').snapshots.passed),0);
 assert.equal(creationAttempts,2);
 await page.screenshot({path:path.join(evidence,'created.png')});
 await page.unroute('**/snapshots',createSnapshot);
 await navigation.getByRole('button',{name:/site:nested.card:Broken/}).click();
 await page.getByRole('heading',{name:'Test error',exact:true}).waitFor();
 assert.equal(await details.getByRole('button',{name:'Update snapshot',exact:true}).count(),0);
 await page.getByRole('region',{name:'Stack trace',exact:true}).waitFor();
 await page.getByText('#0 <img src=x onerror=bad()>',{exact:true}).waitFor();
 assert.equal(await page.locator('.fs-snapshot-view img,.fs-snapshot-view script').count(),0);
 await navigation.getByRole('button',{name:/site:nested.card:Second/}).click();
 await page.getByRole('heading',{name:'Snapshot mismatch',exact:true}).waitFor();
 assert.equal(await navigation.getByRole('button',{name:/site:nested.card:Second/}).getAttribute('aria-current'),'true');
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
 await page.getByRole('button',{name:'Go to Component',exact:true}).click();
 await page.waitForFunction(()=>window.selections===1);
 assert.match(await page.evaluate(()=>window.openedComponentUrl),/componentVariant=site%3Anested.card%3ASecond/);
 assert.match(await page.evaluate(()=>window.openedComponentUrl),/site=preview&language=de/);
 // A failed update retains the mismatch, then a successful update affects only its variant.
 await page.setViewportSize({width:1000,height:1000});
 await page.getByRole('button',{name:'Inspect snapshot failures for Card',exact:true}).click();
 await page.getByRole('navigation',{name:'Failed variants'}).getByRole('button',{name:/site:nested.card:Second/}).click();
 const failUpdate=async(route)=>{
  if((route.request().postData()||'').includes('update')) await route.fulfill({status:503,json:{success:false,message:'Update unavailable'}});
  else await route.continue();
 };
 await page.route('**/snapshots',failUpdate);
 await page.getByRole('button',{name:'Update snapshot',exact:true}).click();
 await details.getByText('Update unavailable',{exact:true}).waitFor();
 assert.equal(await details.locator('.fs-snapshot-diff').count(),1);
 assert.equal(await details.getByRole('button',{name:'Update snapshot',exact:true}).isEnabled(),true);
 await page.unroute('**/snapshots',failUpdate);
 const applicationError=async(route)=>{
  await route.fulfill({json:{success:true,result:{identifier:ids[1],status:'error',message:'Cannot write baseline.',trace:'#0 <img src=x onerror="window.injected=true">',diff:[],expected:'',actual:''}}});
 };
 await page.route('**/snapshots',applicationError);
 await page.getByRole('button',{name:'Update snapshot',exact:true}).click();
 const updateError=details.getByRole('alert',{name:'Snapshot update error',exact:true});
 await updateError.getByRole('heading',{name:'Snapshot update failed',exact:true}).waitFor();
 await updateError.getByText('Cannot write baseline.',{exact:true}).waitFor();
 await page.getByRole('heading',{name:'Snapshot mismatch',exact:true}).waitFor();
 assert.equal(await details.locator('.fs-snapshot-diff').count(),1);
 assert.equal(await details.getByRole('button',{name:'Update snapshot',exact:true}).isEnabled(),true);
 await updateError.getByText('Update error stack trace',{exact:true}).click();
 await updateError.getByText('#0 <img src=x onerror="window.injected=true">',{exact:true}).waitFor();
 assert.equal(await updateError.locator('img,script').count(),0);
 assert.equal(await page.evaluate(()=>document.querySelector('andersundsehr-frontend-studio-component-tree').snapshots.results.get('site:nested.card:Second').status),'failed');
 await page.screenshot({path:path.join(evidence,'update-error.png')});
 await page.unroute('**/snapshots',applicationError);
 let releaseUpdate;
 const updateGate=new Promise(resolve=>{releaseUpdate=resolve;});
 const holdUpdate=async(route)=>{await updateGate;await route.continue();};
 await page.route('**/snapshots',holdUpdate);
 await page.getByRole('button',{name:'Update snapshot',exact:true}).click();
 await page.waitForFunction(()=>document.querySelector('andersundsehr-frontend-studio-component-tree').snapshots.active);
 assert.equal(await page.getByRole('button',{name:'Test all',exact:true}).isDisabled(),true);
 assert.equal(await details.getByRole('button',{name:'Update snapshot',exact:true}).isDisabled(),true);
 releaseUpdate();
 await page.getByRole('heading',{name:'Snapshot updated',exact:true}).waitFor();
 assert.equal(await details.locator('.fs-snapshot-tone-warning').count(),1);
 assert.equal(await details.getByRole('button',{name:'Update snapshot',exact:true}).isDisabled(),true);
 assert.equal(await page.evaluate(()=>document.querySelector('andersundsehr-frontend-studio-component-tree').snapshots.passed),0);
 assert.equal(requests.filter(r=>r.operation==='update').length,1);
 assert.deepEqual(requests.at(-1),{site:'preview',language:'de',scope:ids[1],operation:'update'});
 await page.unroute('**/snapshots',holdUpdate);
 await page.waitForTimeout(350);
 await page.screenshot({path:path.join(evidence,'updated.png')});
 await page.keyboard.press('Escape');
 await page.getByRole('button',{name:'Test all',exact:true}).click();
 await page.getByRole('status').filter({hasText:'2/2 passed'}).waitFor();
 assert.equal(await page.getByRole('button',{name:/✕ Results/}).count(),0);
 updated.clear();
 // Turning off the GUI removes all testing controls but preserves the ordinary tree actions.
 await page.goto(`http://127.0.0.1:${server.address().port}/?gui=off`);
 await page.waitForFunction(()=>window.ready);
 await row('Default').waitFor();
 await row('Default').hover();
 assert.equal(await page.getByRole('button',{name:/^Test /}).count(),0);
 assert.equal(await page.getByRole('region',{name:'HTML snapshot tests',exact:true}).count(),0);
 assert.equal(await page.locator('[aria-label="HTML snapshot tests"]').count(),0);
 assert.ok(await actions('Default').count()>0);
 const disabledRequestCount=requests.length;
 await page.evaluate(()=>document.querySelector('andersundsehr-frontend-studio-component-tree').runSnapshots());
 assert.equal(requests.length,disabledRequestCount);
 await page.screenshot({path:path.join(evidence,'disabled.png')});
 // Production can compare, with an explicitly disabled update button.
 await page.goto(`http://127.0.0.1:${server.address().port}/?production`);
 await page.waitForFunction(()=>window.ready);
 await page.getByRole('button',{name:'Test all',exact:true}).click();
 await resultsButton(1).click();
 await page.getByRole('heading',{name:'Snapshot mismatch',exact:true}).waitFor();
 assert.equal(await details.getByRole('button',{name:'Update snapshot',exact:true}).isDisabled(),true);
 await details.getByText('Production: snapshot files are read-only.',{exact:true}).waitFor();
 await page.keyboard.press('Escape');
 await page.evaluate(()=>{
  const tests=document.querySelector('andersundsehr-frontend-studio-component-tree').snapshots;
  tests.results.set('site:nested.card:Default',{identifier:'site:nested.card:Default',status:'missing',message:'Snapshot is missing. Create it explicitly outside Production.',expected:'',actual:'<p>New</p>'});
  tests.changed();
 });
 await resultsButton(2).click();
 await page.getByRole('navigation',{name:'Failed variants'}).getByRole('button',{name:/site:nested.card:Default/}).click();
 await page.getByRole('heading',{name:'Missing snapshot',exact:true}).waitFor();
 assert.equal(await details.getByRole('button',{name:'Create snapshot',exact:true}).isDisabled(),true);
 await page.keyboard.press('Escape');
 // A failed rediscovery must remove every old green result from the rendered tree.
 let failDiscovery=false;
 let discoveryCatalog=ids;
 await page.route('**/snapshots',async(route)=>{
  const body=route.request().postData()||'';
  if(body.includes('discover')) {
   if(failDiscovery) await route.fulfill({status:503,json:{success:false,message:'Discovery unavailable'}});
   else await route.fulfill({json:{success:true,catalog:discoveryCatalog,identifiers:body.includes('Default')?[ids[0]]:discoveryCatalog}});
  } else {
   const identifier=body.includes('Second')?ids[1]:ids[0];
   await route.fulfill({json:{success:true,result:{identifier,status:'passed',message:'Both samples match.',expected:'',actual:''}}});
  }
 });
 await page.reload();
 await page.waitForFunction(()=>window.ready);
 await page.getByRole('button',{name:'Test all',exact:true}).click();
 await page.getByRole('status').filter({hasText:'2/2 passed'}).waitFor();
 assert.equal(await page.getByRole('img',{name:'All tests passed'}).count(),5);
 assert.equal(await page.getByRole('button',{name:/✕ Results/}).count(),0);
 failDiscovery=true;
 await page.getByRole('button',{name:'Test all',exact:true}).click();
 await page.waitForFunction(()=>!document.querySelector('andersundsehr-frontend-studio-component-tree').snapshots.active);
 await page.getByRole('status').filter({hasText:'0/2 passed'}).waitFor();
 assert.equal(await page.getByRole('img',{name:'All tests passed'}).count(),0);
 await resultsButton(1).click();
 await page.getByRole('heading',{name:'Test error',exact:true}).waitFor();
 await page.getByText('Discovery unavailable',{exact:true}).waitFor();
 await page.waitForTimeout(350);
 await page.screenshot({path:path.join(evidence,'discovery-error.png')});
 // A file change clears stale totals until partial discovery rebuilds the complete catalog.
 await page.keyboard.press('Escape');
 await page.evaluate(()=>document.dispatchEvent(new CustomEvent('frontend-studio:component-files-changed',{detail:{ownAction:true}})));
 await page.getByRole('status').filter({hasText:'0/0 passed'}).waitFor();
 assert.equal(await page.getByRole('button',{name:/✕ Results/}).count(),0);
 await page.screenshot({path:path.join(evidence,'invalidated-catalog.png')});
 failDiscovery=false;
 discoveryCatalog=[ids[0]];
 await row('Default').hover();
 await page.getByRole('button',{name:'Test Default',exact:true}).click();
 await page.getByRole('status').filter({hasText:'1/1 passed'}).waitFor();
 assert.deepEqual(await page.evaluate(()=>document.querySelector('andersundsehr-frontend-studio-component-tree').snapshots.catalog),[ids[0]]);
 discoveryCatalog=ids;
 await page.evaluate(()=>document.dispatchEvent(new CustomEvent('frontend-studio:component-files-changed',{detail:{ownAction:true}})));
 await page.getByRole('status').filter({hasText:'0/0 passed'}).waitFor();
 await page.getByRole('button',{name:'Test all',exact:true}).click();
 await page.getByRole('status').filter({hasText:'2/2 passed'}).waitFor();
 assert.deepEqual(await page.evaluate(()=>document.querySelector('andersundsehr-frontend-studio-component-tree').snapshots.catalog),ids);
 // Navigate to and activate snapshot actions without a mouse or programmatic focus.
 const keyboard=await browser.newPage({viewport:{width:1000,height:780}});
 await keyboard.goto(`http://127.0.0.1:${server.address().port}/`);
 await keyboard.waitForFunction(()=>window.ready);
 await keyboard.getByText('Default',{exact:true}).waitFor();
 for(let index=0;index<15;index++) {
  if(await keyboard.evaluate(()=>document.activeElement?.classList.contains('node'))) break;
  await keyboard.keyboard.press('Tab');
 }
 assert.equal(await keyboard.evaluate(()=>document.activeElement?.classList.contains('node')),true);
 for(let index=0;index<10;index++) {
  if(await keyboard.evaluate(()=>document.activeElement?.dataset.id==='site:nested.card:Default')) break;
  await keyboard.keyboard.press('ArrowDown');
 }
 assert.equal(await keyboard.evaluate(()=>document.activeElement?.dataset.id),'site:nested.card:Default');
 assert.equal(await keyboard.getByRole('button',{name:'Test Default',exact:true}).isVisible(),true);
 await keyboard.keyboard.press('Tab');
 assert.equal(await keyboard.evaluate(()=>document.activeElement?.getAttribute('aria-label')),'Test Default');
 await keyboard.keyboard.press('Enter');
 await keyboard.getByRole('status').filter({hasText:'1/2 passed'}).waitFor();
 await keyboard.keyboard.press('Shift+Tab');
 await keyboard.keyboard.press('ArrowDown');
 assert.equal(await keyboard.evaluate(()=>document.activeElement?.dataset.id),'site:nested.card:Second');
 await keyboard.keyboard.press('Tab');
 assert.equal(await keyboard.evaluate(()=>document.activeElement?.getAttribute('aria-label')),'Test Second');
 await keyboard.keyboard.press('Enter');
 await keyboard.getByRole('button',{name:'Inspect snapshot failures for Second',exact:true}).waitFor();
 await keyboard.keyboard.press('Tab');
 assert.equal(await keyboard.evaluate(()=>document.activeElement?.getAttribute('aria-label')),'Inspect snapshot failures for Second');
 await keyboard.keyboard.press('Enter');
 await keyboard.getByRole('heading',{name:'Snapshot mismatch',exact:true}).waitFor();
 assert.equal(await keyboard.evaluate(()=>window.selections),0);
 await keyboard.screenshot({path:path.join(evidence,'keyboard.png')});
 await keyboard.close();
 // A long failure list fills the dialog without sharing the details' scroll position.
 const layout=await browser.newPage({viewport:{width:1280,height:900}});
 await layout.goto(`http://127.0.0.1:${server.address().port}/`);
 await layout.waitForFunction(()=>window.ready);
 await layout.evaluate(async()=>{
  document.documentElement.dataset.bsTheme='dark';
  document.documentElement.dataset.colorScheme='dark';
  const {showSnapshotDiff}=await import('@andersundsehr/frontend-studio/backend/snapshot-diff.js');
  const failures=Array.from({length:33},(_,index)=>({
   identifier:`site:nested.card:Variant ${index+1}`,status:'failed',message:'Rendered HTML differs from the saved baseline.',
   path:`Card.html-snapshots/html-Variant%20${index+1}@preview@de.snapshot.html`,
   expected:'<div>\n  <article>\n    <h2>Title</h2>\n    Old title\n    <p>After</p>\n  </article>\n</div>',
   actual:'<div>\n  <article>\n    <h2>Title</h2>\n    New title\n    <p>After</p>\n  </article>\n</div>',
   trace:Array.from({length:40},(_,line)=>`#${line} Example rendering frame`).join('\n'),
   diff:Array.from({length:7},(_,line)=>({expected:line+1,actual:line+1,changed:line===3,
    expectedSegments:[{text:['<div>','  <article>','    <h2>Title</h2>','    Old title','    <p>After</p>','  </article>','</div>'][line],changed:line===3}],
    actualSegments:[{text:['<div>','  <article>','    <h2>Title</h2>','    New title','    <p>After</p>','  </article>','</div>'][line],changed:line===3}]})),
  }));
  await showSnapshotDiff(failures,{context:{site:'preview',language:'de'},variantIdentifiers:failures.map(result=>result.identifier),openComponent:()=>{},canUpdate:()=>true,updateSnapshot:async()=>null});
 });
 const failureList=layout.getByRole('navigation',{name:'Failed variants'});
 const failureDetails=layout.getByRole('region',{name:'Failure details',exact:true});
 await failureList.waitFor();
 await layout.waitForTimeout(350);
 const listBounds=await failureList.boundingBox();
 const detailBounds=await failureDetails.boundingBox();
 assert.ok(Math.abs(listBounds.height-detailBounds.height)<2,'Both columns must fill the available dialog height.');
 assert.ok(await failureList.evaluate(el=>el.scrollHeight>el.clientHeight));
 assert.ok(await failureDetails.evaluate(el=>el.scrollHeight>el.clientHeight));
 const listTop=listBounds.y;
 await failureDetails.hover();
 await layout.mouse.wheel(0,350);
 await layout.waitForFunction(()=>document.querySelector('[aria-label="Failure details"]').scrollTop>0);
 const detailScroll=await failureDetails.evaluate(el=>el.scrollTop);
 assert.equal(await failureList.evaluate(el=>el.scrollTop),0);
 assert.equal((await failureList.boundingBox()).y,listTop);
 assert.equal(await layout.locator('.t3js-modal-body').evaluate(el=>el.scrollTop),0);
 await failureList.hover();
 await layout.mouse.wheel(0,350);
 await layout.waitForFunction(()=>document.querySelector('[aria-label="Failed variants"]').scrollTop>0);
 assert.equal(await failureDetails.evaluate(el=>el.scrollTop),detailScroll);
 await failureList.evaluate(el=>{el.scrollTop=0;});
 await failureDetails.evaluate(el=>{el.scrollTop=0;});
 const [red,green,blue]=await failureDetails.locator('.fs-snapshot-state').evaluate(el=>{
  const canvas=document.createElement('canvas');
  const context=canvas.getContext('2d');
  context.fillStyle=getComputedStyle(el).color;
  context.fillRect(0,0,1,1);
  return Array.from(context.getImageData(0,0,1,1).data.slice(0,3));
 });
 assert.ok(red>green&&red>blue,'The mismatch icon must stay red in the dark theme.');
 await layout.screenshot({path:path.join(evidence,'multiple-diff.png')});
 // Clipboard denial reports a separate error and keeps the snapshot available.
 await layout.evaluate(()=>{navigator.clipboard.writeText=async()=>{throw Error('Clipboard denied');};});
 await failureDetails.getByRole('button',{name:'Copy Snapshot file location',exact:true}).click();
 await failureDetails.getByText('Could not copy snapshot file location.',{exact:true}).waitFor();
 assert.equal(await failureDetails.getByRole('button',{name:'Copy Snapshot file location',exact:true}).isEnabled(),true);
 assert.equal(await failureDetails.locator('.fs-snapshot-diff').count(),1);
 await layout.setViewportSize({width:390,height:780});
 assert.ok((await failureList.boundingBox()).height<=160);
 assert.ok((await failureDetails.boundingBox()).height>200);
 assert.equal(await layout.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
 await layout.screenshot({path:path.join(evidence,'multiple-narrow-diff.png')});
 await layout.close();
 console.log('PASS real TYPO3 tree/Lit/modal: GUI setting, isolated updates, retryable HTTP and application errors, escaped update traces, pending/Production restrictions, colored details, persistent status buttons, hover/focus actions, stale discovery, catalog invalidation and changed totals after rediscovery, two-line diff context, independent full-height failure navigation, light/dark themes, copy-location success/denial, component navigation, keyboard-only testing and failure inspection, and 390px layout; AJAX and shell mocked.');
}finally{await browser.close();server.close();}
