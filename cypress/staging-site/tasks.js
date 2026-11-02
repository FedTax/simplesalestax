const fs=require('node:fs/promises');
const path=require('node:path');
const {root,target,access,Client,loadCredentials,redactor}=require('./core');
module.exports=async(on,config)=>{
  const t=target(process.env.SST_QA_TARGET);const a=await access(t);const creds=await loadCredentials(root);const redact=redactor({...creds,...a.admin,leaseToken:a.leaseToken,...Object.fromEntries(Object.entries(a.customers).map(([k,v])=>[k,v.password]))});
  const client=new Client(t,a);await client.login();const site=await client.control('info');
  const dir=path.join(root,'cypress/results/staging-site',t.id,a.runId);await fs.mkdir(dir,{recursive:true});
  const progress=async operation=>fs.writeFile(path.join(dir,'activity.json'),JSON.stringify({operation,time:new Date().toISOString()}));
  await progress('browser-startup');
  await fs.writeFile(path.join(dir,config.env.qaRestoring?'restoration-selection.json':'selection.json'),JSON.stringify({qaGroup:config.env.qaGroup||null,qaCase:config.env.qaCase||null,qaPolicyGroup:config.env.qaPolicyGroup||null,qaPolicyCase:config.env.qaPolicyCase||null},null,2));
  Object.assign(config,{baseUrl:t.url,specPattern:config.env.qaRestoring?'cypress/staging-site/restoration-ui.cy.js':'cypress/staging-site/specs/0[123456789]-*.cy.js',supportFile:'cypress/staging-site/support.js',video:false,trashAssetsBeforeRuns:false,retries:0,viewportWidth:1440,viewportHeight:900,defaultCommandTimeout:20000,requestTimeout:60000,responseTimeout:90000,taskTimeout:660000,screenshotsFolder:path.join(dir,'screenshots'),downloadsFolder:path.join(root,'cypress/.staging-private',t.id,'downloads')});
  const allowedEnv=['qaGroup','qaCase','qaPolicyGroup','qaPolicyCase','qaRestoring','qaExistingPositiveTaxOrder'];
  config.env=Object.fromEntries(allowedEnv.filter(key=>Object.prototype.hasOwnProperty.call(config.env,key)).map(key=>[key,config.env[key]]));
  config.env.suite='staging-site';
  config.env.qaRunId=a.runId;
  const certificateFixtures={},certificateMappings={};
  on('task',{
    qa:async({action,...data})=>{await progress(action);try{const result=await client.control(action,data);await progress(action+'-complete');return redact(result);}catch(e){throw new Error(redact(e.message));}},
    qaCertificateFixture:({surface,actorId})=>{const entry=certificateFixtures[surface];return entry&&entry.actorId===actorId?entry:null;},
    qaPlannedCases:async data=>{await progress('registered-scenarios');await fs.writeFile(path.join(dir,`planned-${path.basename(data.spec)}.json`),JSON.stringify(data,null,2));return null;},
    qaSite:()=>site,
    qaLease:()=>a.leaseToken,
    qaRestoration:()=>client.control('restorePreview'),
    qaConnectionBoundary:async()=>{
      const folder=path.join(dir,'mutation-checkpoints');let names=[];
      try{names=await fs.readdir(folder);}catch(error){if(error.code!=='ENOENT')throw error;}
      for(const name of names.filter(x=>x.endsWith('-intent.json'))){
        const key=name.slice(0,-12);
        try{await fs.access(path.join(folder,`${key}-verified.json`));}
        catch{throw new Error(`Uncertain mutation ${key}: verify exact original remote outcome before changing connection or replaying. Original settings restoration remains enabled.`);}
      }
      return true;
    },
    qaMutationIntent:async({id,kind})=>{
      if(!['capture','refund'].includes(kind)||id===851)throw new Error('Unsupported or protected mutation.');
      const order=await client.control('order',{id});const folder=path.join(dir,'mutation-checkpoints');await fs.mkdir(folder,{recursive:true});
      const key=`${id}-${kind}-${order.refundIds.length}`;
      await fs.writeFile(path.join(folder,`${key}-intent.json`),JSON.stringify(redact({id,kind,remoteId:order.remoteId,refunded:order.refunded,refundIds:order.refundIds,time:new Date().toISOString()}),null,2),{flag:'wx'});
      return key;
    },
    qaMutationVerified:async({key,evidence})=>{
      if(!/^[0-9]+-(capture|refund)-[0-9]+$/.test(key)||!evidence)throw new Error('A specific mutation and its evidence are required.');
      await fs.writeFile(path.join(dir,'mutation-checkpoints',`${key}-verified.json`),JSON.stringify({evidence,time:new Date().toISOString()},null,2));return true;
    },
    qaLogin:({role='buyer'}={})=>role==='admin'?a.admin:a.customers[role],
    qaCredentials:profile=>{if(!['realtime','dataImport'].includes(profile))throw new Error('Unknown connection profile.');const suffix=profile==='realtime'?'Realtime':'DataImport';return {login:creds[`apiLoginID${suffix}`],key:creds[`apiKey${suffix}`]};},
    qaAwaitDashboard:async({id,phase='capture'})=>{
      const order=await client.control('order',{id});
      if(Number(id)===851)throw new Error('Protected order 851 cannot enter a QA mutation workflow.');
      const current=await client.control('info');
      const checkpoint={id,phase,profile:current.settings.data_mover?'dataImport':'realtime',apiVersion:current.settings.api_version,customerId:order.customerId,nameLabel:order.nameLabel,lines:order.lines,packages:order.packages,certificateMapping:certificateMappings[id]||null,remoteId:order.remoteId,total:order.total,tax:order.tax,refunded:order.refunded,refundIds:order.refundIds,requestedAt:new Date().toISOString()};
      const suffix=phase==='return'?`-${order.refundIds.join('-')}`:'';
      const folder=path.join(dir,'dashboard-checkpoints');await fs.mkdir(folder,{recursive:true});
      await fs.writeFile(path.join(folder,`${id}-${phase}${suffix}-pending.json`),JSON.stringify(redact(checkpoint),null,2));
      const deadline=Date.now()+600000;
      while(Date.now()<deadline){
        await progress(`dashboard-${phase}-${id}`);
        try{
          const approved=JSON.parse(await fs.readFile(path.join(folder,`${id}-${phase}${suffix}-verified.json`),'utf8'));
          if(approved.id!==id||approved.phase!==phase||approved.remoteId!==order.remoteId||approved.total!==order.total||approved.tax!==order.tax||approved.refunded!==order.refunded||!approved.accountVerified||!approved.reloaded||!approved.evidence)throw new Error('Dashboard checkpoint does not match this exact order/outcome.');
          return approved;
        }catch(error){if(error.code!=='ENOENT')throw error;}
        await new Promise(resolve=>setTimeout(resolve,1500));
      }
      throw new Error(`Dashboard ${phase} verification deadline exceeded for Woo order ${id}; no automatic transaction replay.`);
    },
    qaResult:async data=>{await progress('scenario-result');if(data.id==='single-purchase-certificate-mapping')certificateMappings[data.orderId]=data;if(data.id==='CERT-submission'&&data.state==='MN'){certificateFixtures[data.surface]={certificateId:data.certificateId,actorId:data.actorId,source:'browser creation',version:data.version,metadataVerified:false};await fs.writeFile(path.join(dir,'certificate-fixtures.json'),JSON.stringify(certificateFixtures,null,2));}if(/^CERT-(wp-self|wp-customer|myaccount)$/.test(data.id||'')&&data.certificateId){certificateFixtures[data.id.slice(5)]={certificateId:data.certificateId,actorId:data.actorId,source:'browser creation',version:data.version,metadataVerified:true};await fs.writeFile(path.join(dir,'certificate-fixtures.json'),JSON.stringify(certificateFixtures,null,2));}await fs.appendFile(path.join(dir,'scenarios.ndjson'),JSON.stringify(redact({...data,time:new Date().toISOString()}))+'\n');return null;},
    qaOrderEvidence:async data=>{await fs.appendFile(path.join(dir,'orders.ndjson'),JSON.stringify(redact(data))+'\n');return null;}
  });
  on('after:spec',async(spec,result)=>{await fs.writeFile(path.join(dir,`${path.basename(spec.name)}.json`),JSON.stringify(redact({spec:spec.relative,stats:result.stats,tests:result.tests?.map(test=>({title:test.title,state:test.state,error:test.displayError||null,attempts:test.attempts?.map(x=>({state:x.state,error:x.error?{message:x.error.message}:null}))}))}),null,2));});
  return config;
};
