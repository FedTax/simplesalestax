import {matchesTitle} from '../selection';
const runIt=(title,fn)=>{if(matchesTitle(title,Cypress.env('qaPolicyCase')))it(title,fn);};
import {task,configure,prepareCart,fillClassic,placeOrder,inspectOrder,status,refund,settingsPath,saveSettings} from '../workflows';
function setSettings(values){
 cy.qaLogin('admin');cy.visit(settingsPath);
 for(const [name,value] of Object.entries(values))cy.get(`#woocommerce_wootax_${name}`).then($el=>{
   if($el.is('select'))cy.wrap($el).select(value,{force:true});
   else if($el.is(':checkbox')){if(value)cy.wrap($el).check({force:true});else cy.wrap($el).uncheck({force:true});}
   else cy.wrap($el).clear().type(String(value));
 });saveSettings();
}
const captureCall=row=>(row.transport==='v1-attempt'&&row.path==='AuthorizedWithCapture')||(row.method==='POST'&&/\/carts\/orders$|\/connections\/:connection\/orders$/.test(row.path));
for(const profile of ['realtime','dataImport'])for(const version of ['v1','v3']){
 const group=Cypress.env('qaPolicyGroup')||Cypress.env('qaGroup');if(group&&group!==`${profile}/${version}`)continue;
 describe(`policy ${profile}/${version}`,()=>{
 let site;
 before(()=>{cy.task('qaSite',null,{log:false}).then(x=>{site=x;});configure(profile,version);});
 afterEach(()=>{task('fee',{fee:'none'});setSettings({capture_trigger:'completed',api_version:version,...(profile==='realtime'?{restrict_exempt:'no',enable_taxcloud_rate_limit:false,force_tax_lookup:false}:{})});});
 for(const trigger of ['processing','both'])runIt(`${trigger} capture policy submits once and supports a full refund`,()=>{
   setSettings({capture_trigger:trigger});let offset;
   task('records').then(rows=>{offset=rows.length;});prepareCart(site);fillClassic(`qa-${trigger}-${Cypress.env('qaRunId').slice(0,8)}@example.test`);
   placeOrder('classic').then(id=>{
     inspectOrder(id,{profile,version,scenario:`${trigger}-pending`}).then(order=>expect(order.taxcloudStatus).not.to.equal('captured'));
     status(id,'processing');inspectOrder(id,{profile,version,scenario:`${trigger}-captured`}).then(order=>expect(order.taxcloudStatus).to.equal('captured'));
     status(id,'completed');task('records').then(rows=>expect(rows.slice(offset).filter(captureCall),'one submission across processing and completed').to.have.length(1));
     refund(id,1);inspectOrder(id,{profile,version,scenario:`${trigger}-refunded`}).then(order=>{expect(order.taxcloudStatus).to.equal('refunded');expect(order.refunded).to.equal(order.total);});
   });
 });
 runIt('persists positive and negative fee totals without double charging',()=>{
   for(const fee of ['positive','negative']){
     task('fee',{fee});prepareCart(site);fillClassic(`qa-fee-${fee}-${Cypress.env('qaRunId').slice(0,8)}@example.test`);
     placeOrder('classic').then(id=>inspectOrder(id,{profile,version,scenario:`${fee}-fee`}).then(order=>{
       expect(order.lines.filter(x=>x.type==='fee').map(x=>x.total)).to.include(fee==='positive'?2:-2);
       const net=fee==='positive'?12:8;expect(order.total).to.equal(Number((net+order.tax).toFixed(2)));
     }));
   }
 });
 if(profile==='realtime'){
   runIt('retains package API ownership when the saved API selector changes before capture/refund',()=>{
     prepareCart(site,{quantity:3});fillClassic(`qa-owner-${Cypress.env('qaRunId').slice(0,8)}@example.test`);
     placeOrder('classic').then(id=>{
       setSettings({api_version:version==='v1'?'v3':'v1'});let offset;
       task('records').then(rows=>{offset=rows.length;});status(id,'completed');
       inspectOrder(id,{profile,version,scenario:'api-ownership-captured'}).then(order=>{expect(order.taxcloudStatus).to.equal('captured');order.packages.forEach(p=>expect(p.api_version||'v1').to.equal(version));});
       task('records').then(rows=>{const captures=rows.slice(offset).filter(captureCall);expect(captures).to.have.length(1);expect(captures[0].transport==='v1-attempt').to.equal(version==='v1');});
       refund(id,3);inspectOrder(id,{profile,version,scenario:'api-ownership-refunded'}).then(order=>expect(order.taxcloudStatus).to.equal('refunded'));
       setSettings({api_version:version});
     });
   });
   runIt('restricts exemption controls to the configured exempt customer role',()=>{
     cy.qaLogin('admin');cy.visit(`wp-admin/user-edit.php?user_id=${site.userIds[2]}`);cy.get('#role').select('exempt-customer');cy.get('#submit').click();cy.contains(/user updated/i).should('exist');
     setSettings({show_exempt:'true',restrict_exempt:'yes',exempt_roles:['exempt-customer']});
     for(const layout of ['classic','blocks']){
       prepareCart(site,{layout,role:'buyer'});cy.get(layout==='classic'?'#certificate_id':'#exemption-certificate-input').should('not.exist');
       prepareCart(site,{layout,role:'exempt'});cy.get(layout==='classic'?'#certificate_id':'#exemption-certificate-input').should('exist');
     }
   });
   runIt('enforces a per-customer lookup limit, shows its notice and recovers when disabled',()=>{
     task('clearCustomerRate');setSettings({enable_taxcloud_rate_limit:true,taxcloud_rate_limit_requests:'1',taxcloud_rate_limit_window:'1',taxcloud_rate_limit_scope:'customer',show_rate_limit_notice:true,force_tax_lookup:true});
     let offset;task('records').then(rows=>{offset=rows.length;});prepareCart(site);
     cy.contains('Tax calculation is temporarily unavailable',{timeout:60000}).should('be.visible');
     task('records').then(rows=>{const lookups=rows.slice(offset).filter(r=>r.path==='Lookup'||(r.method==='POST'&&/\/carts$/.test(r.path)));expect(lookups,'one lookup before customer limit').to.have.length(1);});
     setSettings({enable_taxcloud_rate_limit:false,force_tax_lookup:false});task('clearCustomerRate');prepareCart(site);fillClassic(`qa-rate-${Cypress.env('qaRunId').slice(0,8)}@example.test`);
     cy.contains('Tax calculation is temporarily unavailable').should('not.exist');cy.get('#place_order').should('be.enabled');
   });
 }
});
}
