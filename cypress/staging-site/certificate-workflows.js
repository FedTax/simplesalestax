import {task} from './workflows';
export const QA_REASON_DESCRIPTION='SYNTHETIC QA sample resale certificate; staging verification only';
export function certificateFields({layout='classic',version='v3',state='MN'}={}){
 cy.get('#exempt_state').select(state,{force:true});
 cy.get('#tax_type').then($el=>{if($el.is('select'))cy.wrap($el).select('StateIssued',{force:true});else cy.get('input[type="radio"][value="StateIssued"]').check({force:true});});
 cy.get('#state_of_issue').select(state,{force:true});
 cy.get('#id_number').clear().type(`SYNTHETIC-QA-${Cypress.env('qaRunId').slice(0,8)}`,{delay:0});
 cy.get('#purchaser_business_type').select('RetailTrade',{force:true});
 cy.get('#purchaser_exemption_reason').select('Resale',{force:true});
 if(layout==='classic'||version==='v3')cy.get('#purchaser_exemption_reason_value').scrollIntoView({block:'center'}).should('be.visible').clear().type(QA_REASON_DESCRIPTION,{delay:0});
}
export function createManagement(site,surface,version,{state='MN'}={}){
 const role=surface==='myaccount'?'buyer':'admin';const actorId=surface==='wp-self'?site.userIds[0]:site.userIds[1];
 cy.qaLogin(role);cy.visit(surface==='wp-self'?'wp-admin/profile.php':surface==='wp-customer'?`wp-admin/user-edit.php?user_id=${actorId}`:new URL('exemption-certificates/',site.pages.myAccount).href);
 cy.get('.sst-certificate-add').should('be.visible').click();cy.get('.wc-backbone-modal-content').should('be.visible');
 cy.get('#btn-ok').click();cy.get('.woocommerce-invalid-required-field').should('exist');certificateFields({state,version});
 let created;
 cy.intercept('POST','**/admin-ajax.php*',req=>{if(new URL(req.url).searchParams.get('action')==='sst_add_certificate'||String(req.body).includes('action=sst_add_certificate'))req.alias='createCertificate';});
 cy.get('#btn-ok').click();cy.wait('@createCertificate',{timeout:90000}).then(({request,response})=>{
  expect(response.statusCode).to.equal(200);expect(response.body.success,'actual certificate submission').to.equal(true);created=String(response.body.data.certificate_id);expect(created).not.to.be.empty;cy.task('qaResult',{id:'CERT-submission',surface,version,state,actorId,certificateId:created},{log:false});
  if(surface==='wp-customer')expect(decodeURIComponent(String(request.body))).to.include(`user_id=${actorId}`);
 });
 cy.then(()=>cy.get(`#sst-certificates tr[data-id="${created}"]`).should('exist').find('.sst-certificate-view').click());
 cy.get('.wc-backbone-modal-content').should('be.visible').invoke('text').should('match',/Minnesota|MN/);cy.get('.wc-backbone-modal .modal-close-link').click();
 cy.get('.sst-certificate-refresh').click();cy.get('.blockUI',{timeout:90000}).should('not.exist');cy.reload();
 cy.then(()=>{
  cy.get(`#sst-certificates tr[data-id="${created}"]`,{timeout:60000}).should('exist');
  task('remoteCertificates',{actorId}).then(certificates=>{
   const certificate=certificates.find(x=>String(x.certificateId||x.CertificateID||x.id)===created);
   expect(certificate,'matching TaxCloud certificate readback').to.exist;
   expect(String(certificate.customerId),'remote certificate customer').to.equal(String(actorId));
   expect((certificate.states||[]).map(x=>x.abbreviation),'remote exempt state').to.include(state);
   cy.task('qaResult',{id:'CERT-readback',surface,version,state,actorId,certificateId:created,remote:certificate},{log:false});
   expect(certificate.reason,'selected Resale reason persisted remotely').to.equal('Resale');
   expect(certificate.customerBusinessType,'selected retail business persisted remotely').to.equal('RetailTrade');
   expect(certificate.reasonDescription,'supported 20-character reason description').to.equal(QA_REASON_DESCRIPTION.slice(0,20));
   cy.task('qaResult',{id:`CERT-${surface}${state==='MN'?'':'-'+state}`,version,actorId,certificateId:created,remote:certificate},{log:false});
  });
 });
 return cy.then(()=>created);
}
