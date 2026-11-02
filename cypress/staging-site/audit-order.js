// Read-only reconciliation after a run. No capture/import/refund or settings write exists here.
const fs=require('node:fs/promises');const path=require('node:path');
const {root,target,loadCredentials,redactor}=require('./core');
async function auditOrder({runId,id,profile,targetUrl}){
 if(!/^[a-f0-9-]{36}$/.test(runId)||!Number.isSafeInteger(id)||id<=0||id===851||!['realtime','dataImport'].includes(profile))throw new Error('A specific non-protected QA order, run and profile are required.');
 const t=target(targetUrl),dir=path.join(root,'cypress/results/staging-site',t.id,runId);
 const env=JSON.parse(await fs.readFile(path.join(dir,'environment.json'),'utf8'));
 const orders=(await fs.readFile(path.join(dir,'orders.ndjson'),'utf8')).trim().split('\n').filter(Boolean).map(JSON.parse);
 const own=orders.find(x=>x.id===id&&x.profile===profile);if(!own)throw new Error('Order/profile is outside this retained run evidence.');
 const remoteId=`sstqa-${runId.slice(0,8)}-${id}_0`;const creds=await loadCredentials(root);const suffix=profile==='realtime'?'Realtime':'DataImport';
 const api=env.taxcloudStagingEndpoints?'https://api.v3.taxcloud.net':'https://api.v3.taxcloud.com';
 const auth=env.taxcloudStagingEndpoints?'https://staging-taxcloudapi.azurewebsites.net/api/v3/auth/token':'https://taxcloudapi-appservice-core-prod.azurewebsites.net/api/v3/auth/token';
 const login=creds[`apiLoginID${suffix}`],key=creds[`apiKey${suffix}`];
 const authResponse=await fetch(auth,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({apiLoginID:login,apiKey:key}),redirect:'error',signal:AbortSignal.timeout(30000)});
 const authBody=await authResponse.json();if(!authResponse.ok||!authBody.access_token)throw new Error('TaxCloud read-only authentication failed.');
 const connection=key;const clean=redactor({...creds,connection,token:authBody.access_token});
 const response=await fetch(`${api}/tax/connections/${encodeURIComponent(connection)}/orders/${encodeURIComponent(remoteId)}?expand=refunds`,{headers:{Authorization:`Bearer ${authBody.access_token}`},redirect:'error',signal:AbortSignal.timeout(30000)});
 let body;try{body=await response.json();}catch{body={nonJson:true};}
 if(response.ok&&body.orderId!==remoteId)throw new Error('Read-only reconciliation returned another order.');
 const result=clean({time:new Date().toISOString(),kind:'api-only-readback',runId,orderId:id,profile,remoteId,httpStatus:response.status,response:body,mutationsReplayed:false,cypressPass:false});
 const file=path.join(dir,'read-only-audits.ndjson');await fs.appendFile(file,JSON.stringify(result)+'\n',{mode:0o600});return result;
}
module.exports={auditOrder};
if(require.main===module){const [runId,rawId,profile,targetUrl]=process.argv.slice(2);auditOrder({runId,id:Number(rawId),profile,targetUrl}).then(x=>console.log(JSON.stringify({orderId:x.orderId,profile:x.profile,httpStatus:x.httpStatus,remoteOrderId:x.response?.orderId||null,completedDate:x.response?.completedDate||null,cypressPass:false}))).catch(()=>{console.error('Read-only audit failed; no mutation was replayed.');process.exitCode=1;});}
