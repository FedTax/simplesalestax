const fs=require('node:fs/promises');
const path=require('node:path');
const {root,target,access,Client,loadCredentials,redactor}=require('./core');
module.exports=async(on,config)=>{
  const t=target(process.env.SST_QA_TARGET);const a=await access(t);const creds=await loadCredentials(root);const redact=redactor({...creds,...a.admin,...Object.fromEntries(Object.entries(a.customers).map(([k,v])=>[k,v.password]))});
  const client=new Client(t,a);await client.login();const site=await client.control('info');
  const dir=path.join(root,'cypress/results/staging-site',t.id,a.runId);await fs.mkdir(dir,{recursive:true});
  await fs.writeFile(path.join(dir,'selection.json'),JSON.stringify({qaGroup:config.env.qaGroup||null,qaCase:config.env.qaCase||null,qaPolicyGroup:config.env.qaPolicyGroup||null,qaPolicyCase:config.env.qaPolicyCase||null},null,2));
  Object.assign(config,{baseUrl:t.url,specPattern:'cypress/staging-site/specs/0[123456]-*.cy.js',supportFile:'cypress/staging-site/support.js',video:false,retries:0,defaultCommandTimeout:20000,requestTimeout:60000,responseTimeout:90000,taskTimeout:120000,screenshotsFolder:path.join(dir,'screenshots'),downloadsFolder:path.join(root,'cypress/.staging-private',t.id,'downloads')});
  delete config.env.REST_API_KEY;delete config.env.REST_API_SECRET;
  config.env.qaRunId=a.runId;
  on('task',{
    qa:async({action,...data})=>{try{return redact(await client.control(action,data));}catch(e){throw new Error(redact(e.message));}},
    qaSite:()=>site,
    qaLogin:({role='buyer'}={})=>role==='admin'?a.admin:a.customers[role],
    qaCredentials:profile=>{if(!['realtime','dataImport'].includes(profile))throw new Error('Unknown connection profile.');const suffix=profile==='realtime'?'Realtime':'DataImport';return {login:creds[`apiLoginID${suffix}`],key:creds[`apiKey${suffix}`]};},
    qaResult:async data=>{await fs.appendFile(path.join(dir,'scenarios.ndjson'),JSON.stringify(redact({...data,time:new Date().toISOString()}))+'\n');return null;},
    qaOrderEvidence:async data=>{await fs.appendFile(path.join(dir,'orders.ndjson'),JSON.stringify(redact(data))+'\n');return null;}
  });
  on('after:spec',async(spec,result)=>{await fs.writeFile(path.join(dir,`${path.basename(spec.name)}.json`),JSON.stringify(redact({spec:spec.relative,stats:result.stats,tests:result.tests?.map(test=>({title:test.title,state:test.state,error:test.displayError||null,attempts:test.attempts?.map(x=>({state:x.state,error:x.error?{message:x.error.message}:null}))}))}),null,2));});
  return config;
};
