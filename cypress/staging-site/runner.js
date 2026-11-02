const fs=require('node:fs/promises');
const path=require('node:path');
const {spawn}=require('node:child_process');
// Only this child process group is stopped; the restoring CLI parent stays alive.
function runCypress(args,{root,dir,targetUrl,inactivityMs=240000,pollMs=5000,spawnProcess=spawn,stopGroup=pid=>process.kill(-pid,'SIGTERM')}={}){
 return new Promise((resolve,reject)=>{
  const child=spawnProcess(process.execPath,[path.join(path.dirname(require.resolve('cypress')),'bin/cypress'),...args],{cwd:root,stdio:'inherit',detached:true,env:{...process.env,CYPRESS_suite:'staging-site',SST_QA_TARGET:targetUrl}});
  let lastProgress=Date.now(),checking=false,stopping=false,finished=false;
  const timer=setInterval(async()=>{
   if(checking||stopping||finished)return;checking=true;
   try{
    try{lastProgress=Math.max(lastProgress,(await fs.stat(path.join(dir,'activity.json'))).mtimeMs);}catch(error){if(error.code!=='ENOENT')throw error;}
    if(Date.now()-lastProgress>inactivityMs){
     stopping=true;
     await fs.writeFile(path.join(dir,'browser-inactivity.json'),JSON.stringify({childPid:child.pid,lastProgress:new Date(lastProgress).toISOString(),terminatedAt:new Date().toISOString(),reason:'No task/result progress; dashboard waits send explicit activity heartbeats. Parent retains restoration responsibility.'},null,2));
     if(!finished)stopGroup(child.pid);
    }
   }catch(error){if(error.code!=='ESRCH')console.error('Cypress activity monitor could not complete its check.');}
   finally{checking=false;}
  },pollMs);
  child.once('error',error=>{finished=true;clearInterval(timer);reject(error);});
  child.once('exit',code=>{finished=true;clearInterval(timer);resolve(code??1);});
 });
}
module.exports={runCypress};
