const fs=require('node:fs/promises');const path=require('node:path');const {createHash}=require('node:crypto');
async function report(dir){
  const names=await fs.readdir(dir);const scenarios=[];const emptySpecs=[];
  for(const name of names.filter(n=>n.endsWith('.cy.js.json'))){
    const data=JSON.parse(await fs.readFile(path.join(dir,name),'utf8'));
    if(!data.tests?.length)emptySpecs.push(data.spec||name);
    for(const t of data.tests||[]){const title=t.title.join(' / ');scenarios.push({id:createHash('sha256').update(title).digest('hex').slice(0,12),title,status:t.state==='passed'?'pass':t.state==='failed'?'fail':'blocked',error:t.error||t.attempts?.find(a=>a.error)?.error?.message||null});}
  }
  // afterEach evidence survives a browser crash that prevents after:spec from firing.
  let partialResults=false;
  try{
    const seen=new Set(scenarios.map(x=>x.title.replaceAll(' / ',' ')));
    for(const line of (await fs.readFile(path.join(dir,'scenarios.ndjson'),'utf8')).trim().split('\n').filter(Boolean)){
      const row=JSON.parse(line);if(!row.title||!['passed','failed','pending','skipped'].includes(row.state)||seen.has(row.title))continue;
      partialResults=true;seen.add(row.title);scenarios.push({id:createHash('sha256').update(row.title).digest('hex').slice(0,12),title:row.title,status:row.state==='passed'?'pass':row.state==='failed'?'fail':'blocked',error:row.error||null});
    }
  }catch(error){if(error.code!=='ENOENT')throw error;}
  let restored=false;try{restored=JSON.parse(await fs.readFile(path.join(dir,'restoration.json'),'utf8')).restored===true;}catch{}
  const totals={pass:0,fail:0,blocked:0};scenarios.forEach(x=>totals[x.status]++);
  const outstanding=[
    'Dashboard record-by-record capture/import/refund reconciliation must be documented by the @Chrome agent.',
    'Legal exemption creation/deletion and single-purchase submissions require appropriate certificate data; form validation alone is not full certificate coverage.',
    'Full deterministic failure injection and Colorado fixture reproduction require the local fixture site; Docker is unavailable on this host.',
    'Coverage of every optional extension, tax-inclusive pricing, rate-limit reset, role exemption, multi-origin and every capture trigger must be checked against the full plan; unlisted scenarios are not presumed to pass.'
  ];
  if(partialResults)outstanding.unshift('A spec did not finish normally; partial afterEach results are retained. Unfinished and unstarted cases are not presumed to pass.');
  if(emptySpecs.length)outstanding.unshift(`No matching tests ran in: ${emptySpecs.join(', ')}. Correct the selection before claiming those specs were executed.`);
  const result={totals,restored,scenarios,emptySpecs,partialResults,outstanding,complete:false};
  await fs.writeFile(path.join(dir,'coverage.json'),JSON.stringify(result,null,2));
  const escape=x=>String(x).replace(/[<>&"']/g,c=>({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&apos;'}[c]));
  await fs.writeFile(path.join(dir,'junit.xml'),`<?xml version="1.0" encoding="UTF-8"?><testsuite name="TaxCloud staging" tests="${scenarios.length}" failures="${totals.fail}" skipped="${totals.blocked}">${scenarios.map(x=>`<testcase name="${escape(x.title)}">${x.status==='fail'?`<failure message="${escape(x.error||'Failed')}"></failure>`:x.status==='blocked'?'<skipped message="Not completed"/>':''}</testcase>`).join('')}</testsuite>`);
  const text=`# TaxCloud staging run\n\nAutomated scenarios: **${totals.pass} passed, ${totals.fail} failed, ${totals.blocked} blocked/skipped**. Settings restoration: **${restored?'verified':'NOT VERIFIED'}**.\n\nThis is an execution report for the implemented scenarios, not a claim that the whole acceptance plan is complete.\n\n| Scenario | Status |\n| --- | --- |\n${scenarios.map(x=>`| ${x.title.replaceAll('|','\\|')} | ${x.status} |`).join('\n')}\n\n## Outstanding acceptance checks\n\n${outstanding.map(x=>'- '+x).join('\n')}\n\nSee environment.json, api-evidence.json, orders.ndjson and restoration.json for evidence. Dashboard checks are separate. Test data and the helper are retained.\n`;
  await fs.writeFile(path.join(dir,'REPORT.md'),text);return result;
}
module.exports={report};
if(require.main===module)report(path.resolve(process.argv[2])).then(r=>console.log(JSON.stringify({totals:r.totals,restored:r.restored}))).catch(e=>{console.error(e.message);process.exitCode=1;});
