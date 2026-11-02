const test=require('node:test');const assert=require('node:assert/strict');
const {target,within,redactor}=require('../cypress/staging-site/core');
test('target normalization preserves subdirectory installations and separates site identities',()=>{
  const a=target('https://example.test/shop');assert.equal(a.url,'https://example.test/shop/');assert.notEqual(a.id,target('https://other.test/shop/').id);assert.notEqual(a.id,target('https://example.test/').id);
  assert.equal(within(a,'wp-admin/'),'https://example.test/shop/wp-admin/');
});
test('refuses credentials, unsafe protocols and requests escaping the configured site',()=>{
  for(const url of ['http://example.test/','https://user:secret@example.test/','https://example.test/?secret=x','https://example.test/#fragment'])assert.throws(()=>target(url));
  const t=target('https://example.test/shop/');for(const url of ['https://other.test/','//other.test/','/wp-admin/','../wp-admin/'])assert.throws(()=>within(t,url));
});
test('redacts both credentials and bearer tokens without destroying evidence JSON',()=>{
  const clean=redactor({realtime:'first-key-secret',dataImport:'second-key-secret'});
  assert.deepEqual(clean({request:'first-key-secret',response:'second-key-secret',url:'/connections/private/orders',auth:'Bearer token-value'}),{request:'[redacted]',response:'[redacted]',url:'/connections/:connection/orders',auth:'Bearer [redacted]'});
});
test('retries a transport failure once for evidence reads, never for helper mutations',async()=>{
  const {Client}=require('../cypress/staging-site/core');
  const client=new Client(target('https://example.test/'),{runId:'run'});let calls=0;
  client.request=async()=>{calls++;if(calls===1)throw new Error('fetch failed');return {ok:true,json:async()=>({id:1})};};
  assert.deepEqual(await client.control('order',{id:1}),{id:1});assert.equal(calls,2);
  for(const action of ['layout','restore']){calls=0;client.request=async()=>{calls++;throw new Error('fetch failed');};await assert.rejects(client.control(action),/fetch failed/);assert.equal(calls,1);}
});
test('a selected spec with zero matching tests stays visible as an execution gap',async()=>{
  const fs=require('node:fs/promises'),os=require('node:os'),path=require('node:path');
  const dir=await fs.mkdtemp(path.join(os.tmpdir(),'sst-report-'));
  try{
    await fs.writeFile(path.join(dir,'01-workflows.cy.js.json'),JSON.stringify({spec:'01-workflows.cy.js',tests:[],stats:{tests:0}}));
    const result=await require('../cypress/staging-site/report').report(dir);
    assert.deepEqual(result.emptySpecs,['01-workflows.cy.js']);assert.equal(result.complete,false);
    assert.ok(result.outstanding.some(x=>x.includes('No matching tests ran')));
    assert.match(await fs.readFile(path.join(dir,'REPORT.md'),'utf8'),/No matching tests ran/);
  }finally{await fs.rm(dir,{recursive:true,force:true});}
});
test('case filters accept the actual Cypress CLI comma normalization and preserve intended matches',()=>{
 const {matchesTitle}=require('../cypress/staging-site/selection');
 for(const filter of ['places|TIC|calculates|Account','places,TIC,calculates,Account','places a real order|product TIC','placesarealorder,productTIC']){
  assert.equal(matchesTitle('classic buyer places a real order with matching totals',filter),true);
  assert.equal(matchesTitle('product TIC search saves the selected code',filter),true);
  assert.equal(matchesTitle('changes quantity and removes items',filter),false);
 }
 assert.equal(matchesTitle('arbitrary test',undefined),true);
 assert.equal(matchesTitle('persists positive and negative fee totals','fee,ownership,exemption,limit'),true);
 assert.equal(matchesTitle('both capture policy submits once','fee,ownership,exemption,limit'),false);
});
test('preserves partial case results when a browser crash prevents after:spec',async()=>{
 const fs=require('node:fs/promises'),os=require('node:os'),path=require('node:path');
 const dir=await fs.mkdtemp(path.join(os.tmpdir(),'sst-partial-'));
 try{
  await fs.writeFile(path.join(dir,'scenarios.ndjson'),[{title:'checkout passed',state:'passed'},{title:'checkout failed',state:'failed',error:'browser stopped'},{id:'metadata-only'}].map(JSON.stringify).join('\n'));
  const result=await require('../cypress/staging-site/report').report(dir);
  assert.deepEqual(result.totals,{pass:1,fail:1,blocked:0});assert.equal(result.partialResults,true);assert.equal(result.complete,false);
  assert.ok(result.outstanding.some(x=>x.includes('did not finish normally')));
 }finally{await fs.rm(dir,{recursive:true,force:true});}
});

test('remote capture readback rejects wrong IDs, missing completion and financial drift',()=>{
  const {singlePackageCaptureEvidence}=require('../cypress/staging-site/remote-capture');
  const order={id:99,customerId:0,remoteId:'sstqa-12345678-99',total:13.63,tax:1.28};
  const remote={customerId:'customer-0',orderId:order.remoteId+'_0',completedDate:'2026-10-31T10:42:00Z',channel:null,connectionId:'private-connection',lineItems:[{price:12.35,quantity:1,tax:{amount:1.28}}]};
  const evidence=singlePackageCaptureEvidence(remote,order);
  assert.equal(evidence.total,13.63);assert.equal(evidence.channel,null);assert.equal('connectionId' in evidence,false);
  assert.throws(()=>singlePackageCaptureEvidence({...remote,orderId:'other-order'},order),/different order ID/);
  assert.throws(()=>singlePackageCaptureEvidence({...remote,customerId:'customer-7'},order),/customer differs/);
  assert.throws(()=>singlePackageCaptureEvidence({...remote,completedDate:null},order),/completed date/);
  assert.throws(()=>singlePackageCaptureEvidence({...remote,lineItems:[{price:12.35,quantity:2,tax:{amount:1.28}}]},order),/totals differ/);
  assert.throws(()=>singlePackageCaptureEvidence({...remote,lineItems:[{price:'invalid',quantity:1,tax:{amount:1.28}}]},order),/invalid financial/);
});

test('browser inactivity stops only the isolated Cypress group and preserves parent restoration',async()=>{
 const {EventEmitter}=require('node:events');const os=require('node:os');const fs=require('node:fs/promises');const path=require('node:path');
 const {runCypress}=require('../cypress/staging-site/runner');const dir=await fs.mkdtemp(path.join(os.tmpdir(),'sst-watchdog-'));const child=new EventEmitter();child.pid=1234567;let stopped=null;
 try{
  const code=await runCypress(['run','--browser','chrome'],{root:dir,dir,targetUrl:'https://example.test/',inactivityMs:15,pollMs:5,spawnProcess:(command,args,options)=>{assert.equal(options.detached,true);assert.ok(args.includes('chrome'));return child;},stopGroup:pid=>{stopped=pid;child.emit('exit',null);}});
  assert.equal(code,1);assert.equal(stopped,child.pid);assert.notEqual(stopped,process.pid);
  const proof=JSON.parse(await fs.readFile(path.join(dir,'browser-inactivity.json'),'utf8'));assert.equal(proof.childPid,child.pid);
 }finally{await fs.rm(dir,{recursive:true,force:true});}
});

test('dashboard activity heartbeats keep a healthy long Cypress run alive',async()=>{
 const {EventEmitter}=require('node:events');const os=require('node:os');const fs=require('node:fs/promises');const path=require('node:path');
 const {runCypress}=require('../cypress/staging-site/runner');const dir=await fs.mkdtemp(path.join(os.tmpdir(),'sst-heartbeat-'));const child=new EventEmitter();child.pid=1234568;let stopped=false;
 const pulse=setInterval(()=>fs.writeFile(path.join(dir,'activity.json'),JSON.stringify({operation:'dashboard-capture',time:Date.now()})).catch(()=>{}),20);
 const finish=setTimeout(()=>child.emit('exit',0),600);
 try{assert.equal(await runCypress(['run'],{root:dir,dir,targetUrl:'https://example.test/',inactivityMs:200,pollMs:10,spawnProcess:()=>child,stopGroup:()=>{stopped=true;child.emit('exit',null);}}),0);assert.equal(stopped,false);}
 finally{clearInterval(pulse);clearTimeout(finish);await fs.rm(dir,{recursive:true,force:true});}
});

test('evidence redaction removes guest order keys and nonce query values',()=>{
 const {redactor}=require('../cypress/staging-site/core');const clean=redactor({});
 assert.equal(clean('https://example.test/order-pay/12/?key=wc_order_private&_wpnonce=private-nonce&view=1'),'https://example.test/order-pay/12/?key=[redacted]&_wpnonce=[redacted]&view=1');
});

test('registered unfinished scenarios stay blocked and API/restoration checks are excluded from browser E2E totals',async()=>{
 const fs=require('node:fs/promises'),os=require('node:os'),path=require('node:path');const dir=await fs.mkdtemp(path.join(os.tmpdir(),'sst-inventory-'));
 try{
  await fs.writeFile(path.join(dir,'planned-09.cy.js.json'),JSON.stringify({spec:'09.cy.js',titles:['group first browser flow','group unfinished browser flow']}));
  await fs.writeFile(path.join(dir,'09.cy.js.json'),JSON.stringify({spec:'09.cy.js',tests:[{title:['group','first browser flow'],state:'passed'}]}));
  await fs.writeFile(path.join(dir,'restoration-ui.cy.js.json'),JSON.stringify({spec:'restoration-ui.cy.js',tests:[{title:['Original credential and origin restoration UI','restores'],state:'passed'}]}));
  await fs.writeFile(path.join(dir,'04.cy.js.json'),JSON.stringify({spec:'04.cy.js',tests:[{title:['Admin','downloads a debug report'],state:'passed'}]}));
  await fs.writeFile(path.join(dir,'execution-selection.json'),JSON.stringify({selectedSpecs:['09.cy.js','07.cy.js']}));
  const report=await require('../cypress/staging-site/report').report(dir);
  assert.deepEqual(report.browserE2E,{pass:1,fail:0,blocked:1});assert.equal(report.totals.pass,3);assert.ok(report.emptySpecs.some(x=>x.includes('07.cy.js')));assert.equal(report.partialResults,true);assert.ok(!report.emptySpecs.some(x=>x==='09.cy.js'));
 }finally{await fs.rm(dir,{recursive:true,force:true});}
});


test('capture journal excludes disabled integration and completed outcomes',()=>{
 const {captureIntentRequired}=require('../cypress/staging-site/mutation-policy');
 const active={disable_integration:'no',capture_orders_in_taxcloud:'yes',capture_trigger:'completed'};
 assert.equal(captureIntentRequired(active,{taxcloudStatus:''},'completed'),true);
 assert.equal(captureIntentRequired({...active,disable_integration:'yes'},{taxcloudStatus:''},'completed'),false);
 assert.equal(captureIntentRequired({...active,capture_orders_in_taxcloud:'no'},{taxcloudStatus:''},'completed'),false);
 assert.equal(captureIntentRequired(active,{taxcloudStatus:''},'processing'),false);
 for(const taxcloudStatus of ['captured','partially_refunded','refunded'])assert.equal(captureIntentRequired(active,{taxcloudStatus},'completed'),false);
 assert.equal(captureIntentRequired({...active,capture_trigger:'both'},{taxcloudStatus:''},'processing'),true);
});


test('single-purchase detail hashes map only to one matching remote certificate',()=>{
 const {singlePurchaseMapping}=require('../cypress/staging-site/certificate-evidence');
 const order={id:100,customerId:8,billingName:'Txc User 90',packages:[{certificate_id:'a'.repeat(32)}]};
 const cert={certificateId:'11111111-2222-4333-8444-555555555555',singlePurchase:true,customerId:'8',customerName:'Txc User 90',states:[{abbreviation:'MN'}],reason:'Resale'};
 assert.equal(singlePurchaseMapping(order,[cert]).certificateId,cert.certificateId);
 for(const change of [{customerId:'9'},{customerName:'Txc User 91'},{states:[{abbreviation:'WA'}]},{singlePurchase:false},{disabledAt:'2026-01-01'}])assert.throws(()=>singlePurchaseMapping(order,[{...cert,...change}]));
 assert.throws(()=>singlePurchaseMapping(order,[cert,{...cert}]));
 assert.throws(()=>singlePurchaseMapping({...order,packages:[{certificate_id:cert.certificateId}]},[cert]));
});
