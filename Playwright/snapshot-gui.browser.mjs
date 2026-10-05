import { chromium } from 'playwright-core';
import { fileURLToPath } from 'node:url';
import { readFile, mkdir } from 'node:fs/promises';
import { createServer } from 'node:http';
import path from 'node:path';
import assert from 'node:assert/strict';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const evidence=process.env.SNAPSHOT_EVIDENCE_DIR || path.join(root, 'var/snapshot-gui-evidence');
const imports={'@andersundsehr/frontend-studio/backend/':'/Resources/Public/JavaScript/Backend/','@typo3/backend/':'/vendor/typo3/cms-backend/Resources/Public/JavaScript/','@typo3/core/':'/vendor/typo3/cms-core/Resources/Public/JavaScript/','~labels/':'/labels/'};
for(const extension of ['core','backend']) {
 const config=await readFile(`${root}/vendor/typo3/cms-${extension}/Configuration/JavaScriptModules.php`,'utf8');
 for(const match of config.matchAll(/'([^']+)'\s*=>\s*'EXT:(core|backend)\/([^']+)'/g)) imports[match[1]]=`/vendor/typo3/cms-${match[2]}/${match[3]}`;
}
const stubs={
 '@typo3/backend/viewport.js':`export default {ContentContainer:{get:()=>window,getUrl:()=>location.href,setUrl:()=>{window.selections++}}};`,
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
const server=createServer(async(req,res)=>{
 try{
 const url=new URL(req.url,'http://localhost');
 if(url.pathname==='/favicon.ico'){res.statusCode=204;res.end();return;}
 if(url.pathname.startsWith('/labels/')) {res.setHeader('Content-Type','text/javascript');res.end('export default new Proxy({get:(key)=>key}, {get:(target,key)=>target[key]??String(key)});');return;}
 if(url.pathname.startsWith('/stubs/')){res.setHeader('Content-Type','text/javascript');res.end(stubs[decodeURIComponent(url.pathname.slice(7))]);return;}
 if(url.pathname==='/tree'){res.setHeader('Content-Type','application/json');res.end(JSON.stringify(nodes));return;}
 if(url.pathname==='/snapshots'){
 let body='';for await(const chunk of req)body+=chunk;
 let data;if(req.headers['content-type']?.startsWith('multipart/')){data=Object.fromEntries(await new Response(body,{headers:{'Content-Type':req.headers['content-type']}}).formData());}else{try{data=JSON.parse(body);}catch{data=Object.fromEntries(new URLSearchParams(body));}}
 requests.push(data);res.setHeader('Content-Type','application/json');
 if(data.operation==='discover'){res.end(JSON.stringify({success:true,catalog:ids,identifiers:ids.filter(id=>!data.scope||id===data.scope||id.startsWith(data.scope+(data.scope==='site:nested.card'?':':'')))}));return;}
 await new Promise(r=>setTimeout(r,80));res.end(JSON.stringify({success:true,result:{identifier:data.scope,status:data.scope.endsWith('Default')?'passed':'failed',message:'Rendered HTML differs from the saved baseline.',path:'_html-snapshots/example.html',expected:'<article>\nOld title\n</article>',actual:'<article>\n<img src=x onerror="window.injected=true">\n</article>'}}));return;
 }
 if(url.pathname==='/'||url.pathname==='/module'){
 res.setHeader('Content-Type','text/html');res.end(`<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="/vendor/typo3/cms-backend/Resources/Public/Css/backend.css"><script type="importmap">${JSON.stringify({imports})}</script><style>body{padding:20px;background:#f4f4f4}.demo-tree{width:min(440px,100%);height:620px;background:white;border:1px solid #aaa}h1{font-size:24px}.node-action{visibility:visible!important;opacity:1!important}</style></head><body><h1>Frontend Studio · HTML tests</h1><div data-frontend-studio-variant-header data-selected-site-identifier="preview" data-selected-language-hreflang="de">Preview / German</div><div class="demo-tree"><andersundsehr-frontend-studio-component-tree-container></andersundsehr-frontend-studio-component-tree-container></div><script>window.selections=0;window.TYPO3={settings:{ajaxUrls:{frontend_studio_component_tree_data:'/tree',frontend_studio_component_tree_filter:'/tree',frontend_studio_snapshot:'/snapshots'},ContextHelp:{},formEngine:{}},lang:{},ModuleMenu:{App:{getCurrentModule:()=> 'admin_frontendstudio'}},Backend:{NavigationContainer:{}}};</script><script type="module">await import('@andersundsehr/frontend-studio/backend/component-tree-container.js');window.ready=true;</script></body></html>`);return;
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
 page.on('pageerror',e=>console.error('PAGE ERROR',e.stack));
 page.on('console',m=>{if(m.type()==='error')console.error('CONSOLE',m.text())});
 await page.goto(`http://127.0.0.1:${server.address().port}/`);
 await page.waitForFunction(()=>window.ready, null, {timeout:10000});
 await page.getByRole('button',{name:'Test Default',exact:true}).click();
 await page.getByRole('status').filter({hasText:'1/2 passed'}).waitFor();
 assert.equal(await page.getByRole('img',{name:'All tests passed'}).count(),1);
 await page.getByRole('button',{name:'Test Nested',exact:true}).click();
 await page.getByRole('button',{name:'Inspect snapshot failures for Nested',exact:true}).waitFor();
 await page.evaluate(()=>{const tree=document.querySelector('andersundsehr-frontend-studio-component-tree');tree.hideChildren(tree.nodes.find(node=>node.nodeType==='folder'));});
 await page.getByRole('button',{name:'Test all',exact:true}).click();
 await page.getByRole('status').filter({hasText:'1/2 passed'}).waitFor();
 await page.waitForFunction(()=>!document.querySelector('andersundsehr-frontend-studio-component-tree').snapshots.active);
 await page.evaluate(()=>{const tree=document.querySelector('andersundsehr-frontend-studio-component-tree');tree.showChildren(tree.nodes.find(node=>node.nodeType==='folder'));});
 assert.equal(await page.evaluate(()=>window.selections),0);
 await mkdir(evidence,{recursive:true});
 await page.screenshot({path:path.join(evidence,'tree.png')});
 await page.getByRole('button',{name:'Inspect snapshot failures for Card',exact:true}).click();
 await page.getByText('HTML snapshot results',{exact:true}).waitFor();
 await page.locator('details summary').waitFor();
 await page.waitForTimeout(350);
 assert.equal(await page.evaluate(()=>window.injected),undefined);
 await page.screenshot({path:path.join(evidence,'diff.png')});
 await page.getByRole('button',{name:'Close',exact:true}).last().click();
 await page.getByRole('button',{name:'Inspect snapshot failures for Card',exact:true}).focus();
 await page.keyboard.press('Enter');
 await page.getByText('HTML snapshot results',{exact:true}).waitFor();
 await page.locator('details summary').waitFor();
 await page.waitForTimeout(350);
 await page.keyboard.press('Escape');
 await page.setViewportSize({width:390,height:780});
 await page.screenshot({path:path.join(evidence,'narrow.png')});
 await page.getByRole('button',{name:'Inspect snapshot failures for Card',exact:true}).click();
 await page.locator('details summary').waitFor();
 await page.waitForTimeout(350);
 await page.screenshot({path:path.join(evidence,'narrow-diff.png')});
 assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
 await page.keyboard.press('Escape');
 assert.equal(await page.evaluate(()=>window.selections),0);
 assert.equal(requests.filter(r=>r.operation==='run').length,5);
 console.log('PASS real TYPO3 tree/Lit/modal; scoped controls, progress, aggregation, no selection, escaped HTML, keyboard close/reopen; AJAX and shell context mocked.');
}finally{await browser.close();server.close();}
