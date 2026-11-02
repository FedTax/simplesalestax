const fs = require('node:fs/promises');
const path = require('node:path');
const {execFile} = require('node:child_process');
const execFileAsync=require('node:util').promisify(execFile);
const {runCypress}=require('./runner');
const {createHash}=require('node:crypto');
const {root,target,within,redactor,access,privatePath,Client,loadCredentials} = require('./core');
process.umask(0o077);
(async()=>{
  const [action='run',...args]=process.argv.slice(2);
  const option = name => { const flag=args.find(x=>x.startsWith(`--${name}=`)); return flag?.slice(name.length+3); };
  const t=target(option('target-url')); const creds=await loadCredentials(root); const redact=redactor(creds);
  if(process.env.CI && process.env.CI!=='false') throw new Error('Remote staging verification is disabled in CI.');
  if(action==='setup') {
    const file=option('access-file');
    if(!file) {
      const zip=path.join(root,'cypress/results/staging-site/sst-staging-helper.zip');
      await fs.mkdir(path.dirname(zip),{recursive:true});
      await execFileAsync('php',[path.join(__dirname,'package-helper.php'),path.join(__dirname,'helper/sst-staging-helper.php'),zip]);
      console.log(`Reviewed helper package: ${zip}`);
      console.log(`In logged-in @Chrome, upload the packaged helper, open ${within(t,'wp-admin/tools.php?page=sst-staging-qa')}, click Prepare test site, and Download private runner access. Then rerun setup with --access-file=/absolute/path/to/download.`);
      process.exitCode=2;return;
    }
    let imported;try{imported=JSON.parse(await fs.readFile(path.resolve(file),'utf8'));}catch{throw new Error('Cannot read private access download.');}
    if(target(imported.targetUrl).url!==t.url || !imported.admin?.password) throw new Error('Access download is not valid for the selected target.');
    await fs.mkdir(path.dirname(privatePath(t)),{recursive:true,mode:0o700});
    await fs.writeFile(privatePath(t),JSON.stringify(imported),{mode:0o600});
    const a=await access(t);const client=new Client(t,a);await client.login();const info=await client.control('info');
    console.log(JSON.stringify({target:t.url,runId:a.runId,active:info.active,versions:info.versions,fixtureCount:Object.keys(info.products).length},null,2));return;
  }
  if(action==='preflight') {
    const response=await fetch(t.url,{redirect:'manual',signal:AbortSignal.timeout(30000)});
    if(response.headers.get('location')) within(t,new URL(response.headers.get('location'),t.url).href);
    console.log(JSON.stringify({target:t.url,httpStatus:response.status,credentialPairsValid:true,chromeSessionCheck:'Use @Chrome for WP and https://app.taxcloud.com/; Cypress uses its own Chrome instance.'},null,2));
    try{const a=await access(t);const c=new Client(t,a);await c.login();const i=await c.control('info');console.log(JSON.stringify({runId:a.runId,active:i.active,versions:i.versions,settings:i.settings},null,2));}catch(e){console.log(redact(e.message));}
    return;
  }
  const a=await access(t);const client=new Client(t,a);await client.login();
  if(option('run-id') && option('run-id')!==a.runId) throw new Error('Requested run ID does not match the target access file.');
  const dir=path.join(root,'cypress/results/staging-site',t.id,a.runId);await fs.mkdir(dir,{recursive:true});
  if(action==='restore') { const result=await client.control('restore');await fs.writeFile(path.join(dir,'restoration.json'),JSON.stringify(result,null,2));await fs.rm(privatePath(t),{force:true});console.log(JSON.stringify(result,null,2));return; }
  if(!['run','open'].includes(action)) throw new Error('Choose preflight, setup, run, open or restore.');
  const lock=path.join(path.dirname(privatePath(t)),'run.lock');let handle;
  try{handle=await fs.open(lock,'wx',0o600);}catch{throw new Error('A target run lock exists. Inspect the checkpoint and restore the interrupted run before starting another.');}
  await handle.writeFile(JSON.stringify({runId:a.runId,pid:process.pid,started:new Date().toISOString()}));await handle.close();
  let code=1;
  try{
    const info=await client.control('info');if(!info.active) throw new Error('Run is idle. Prepare a fresh run in @Chrome.');
    const harnessFiles=(await fs.readdir(__dirname,{recursive:true})).filter(file=>/\.(js|php|json)$/.test(file));
    const harness={};for(const file of harnessFiles)harness[file]=createHash('sha256').update(await fs.readFile(path.join(__dirname,file))).digest('hex');
    await fs.writeFile(path.join(dir,'environment.json'),JSON.stringify(redact({...info,node:process.version,harness}),null,2));
    const forwarded=args.filter(x=>!/^--(?:target-url|run-id|access-file)=/.test(x));
    const specFiles=(await fs.readdir(path.join(__dirname,'specs'))).filter(file=>/^0[1-9]-.*\.cy\.js$/.test(file)).map(file=>'cypress/staging-site/specs/'+file);
    const specFlag=forwarded.find(x=>x.startsWith('--spec='))?.slice(7)||(forwarded.includes('--spec')?forwarded[forwarded.indexOf('--spec')+1]:null);
    const selectedSpecs=specFlag?specFiles.filter(file=>specFlag.split(',').some(pattern=>{
      const relative=pattern.startsWith(root+path.sep)?pattern.slice(root.length+1):pattern;
      const regex='^'+relative.replace(/[.+?^${}()|[\]\\]/g,'\\$&').replaceAll('*','.*')+'$';
      return new RegExp(regex).test(file);
    })):specFiles;
    await fs.writeFile(path.join(dir,'execution-selection.json'),JSON.stringify({selectedSpecs,arguments:forwarded},null,2));
    code=await runCypress([action==='open'?'open':'run',...(action==='open'?['--e2e']:[]),'--browser','chrome',...forwarded],{root,dir,targetUrl:t.url,inactivityMs:action==='open'?86400000:240000});
  }finally{
    let evidence,restore;
    // Restore credential/origin controls through the real browser UI before exact snapshot fallback.
    // This browser only saves settings; it cannot replay captures, imports, certificates or refunds.
    try {
      const uiCode=await runCypress(['run','--browser','chrome','--spec','cypress/staging-site/restoration-ui.cy.js','--env','qaRestoring=true'],{root,dir,targetUrl:t.url});
      if(uiCode!==0){code=1;await fs.writeFile(path.join(dir,'restoration-ui-gap.txt'),'Browser settings restoration failed; inspect its preserved failure. Exact helper snapshot fallback still runs.');}
    }catch(error){code=1;await fs.writeFile(path.join(dir,'restoration-ui-gap.txt'),redact(error.message));}

    try{await fs.writeFile(path.join(dir,'retained-resources.json'),JSON.stringify(redact(await client.control('info')),null,2));}catch(error){await fs.writeFile(path.join(dir,'resources-error.txt'),redact(error.message));}
    try{evidence=await client.control('records');await fs.writeFile(path.join(dir,'api-evidence.json'),JSON.stringify(redact(evidence),null,2));}catch(e){await fs.writeFile(path.join(dir,'evidence-error.txt'),redact(e.message));}
    try{restore=await client.control('restore');await fs.writeFile(path.join(dir,'restoration.json'),JSON.stringify(restore,null,2));await fs.rm(privatePath(t),{force:true});}catch(e){code=1;await fs.writeFile(path.join(dir,'RESTORATION-REQUIRED.txt'),redact(e.message));console.error('Settings restoration failed. Use logged-in @Chrome recovery; private access is retained.');}
    if(restore?.restored)await fs.rm(lock,{force:true});
    const summary=await require('./report').report(dir);
    if(summary.emptySpecs.length){code=1;console.error('One or more selected specs ran no tests. Review selection.json and the report.');}
    console.log(`Evidence directory: ${dir}`);
  }
  process.exitCode=code;
})().catch(e=>{console.error(e.message);process.exitCode=1;});
