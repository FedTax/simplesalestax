import {singlePurchaseMapping} from '../certificate-evidence';
import {matchesTitle} from '../selection';
import {task,configure,money,prepareCart,fillClassic,fillBlocks,placeOrder,inspectOrder,status,verifyRemoteCapture} from '../workflows';
const runIt=(title,fn)=>{if(matchesTitle(title,Cypress.env('qaCase')))it(title,fn);};
import {adminOrder,recalculate,saveOrder} from '../admin-workflows';
import {certificateFields,createManagement} from '../certificate-workflows';
for(const profile of ['realtime','dataImport'])for(const version of ['v1','v3']){
 if(Cypress.env('qaGroup')&&Cypress.env('qaGroup')!==`${profile}/${version}`)continue;
 describe(`certificates ${profile}/${version}`,()=>{
  let site;before(()=>{cy.task('qaSite',null,{log:false}).then(x=>{site=x;});configure(profile,version);});
  if(profile==='realtime'){
   for(const [surface,id] of [['wp-self','CERT-WP-SELF'],['wp-customer','CERT-WP-CUSTOMER'],['myaccount','CERT-MYACCOUNT']])runIt(`[${id}] actually submits, views and refreshes a synthetic certificate`,()=>createManagement(site,surface,version));
   for(const surface of ['wp-self','wp-customer','myaccount'])runIt(`[CERT-X-SURFACE] ${surface} certificate applies unchanged in both checkouts and admin order entry`,()=>{
    const actorId=surface==='wp-self'?site.userIds[0]:site.userIds[1];const role=surface==='wp-self'?'admin':'buyer';let certificateId;
    cy.task('qaCertificateFixture',{surface,actorId},{log:false}).then(fixture=>task('remoteCertificates',{actorId}).then(rows=>{
     if(fixture&&rows.some(x=>x.certificateId===fixture.certificateId)){expect(fixture.metadataVerified,'source certificate metadata was verified').to.equal(true);certificateId=fixture.certificateId;}
     else createManagement(site,surface,version).then(id=>{certificateId=id;});
    }));
    for(const layout of ['classic','blocks']){
     prepareCart(site,{layout,role});if(layout==='classic')fillClassic('');else fillBlocks('');
     cy.then(()=>cy.get(layout==='classic'?'#certificate_id':'#exemption-certificate-input').select(certificateId,{force:true}));
     placeOrder(layout).then(id=>{
      inspectOrder(id,{profile,version,surface,layout,scenario:'cross-surface-saved-certificate'}).then(order=>{expect(order.customerId).to.equal(actorId);expect(order.tax).to.equal(0);order.packages.forEach(p=>expect(p.certificate_id).to.equal(certificateId));});
      status(id,'completed');inspectOrder(id,{profile,version,surface,layout,scenario:'cross-surface-captured'}).then(order=>expect(order.taxcloudStatus).to.equal('captured'));
     });
    }
    adminOrder(site,role,{quantity:1,customerId:actorId}).then(id=>{
     cy.then(()=>cy.get('#exempt_cert').select(certificateId,{force:true}));recalculate();saveOrder();cy.reload();
     inspectOrder(id,{profile,version,surface,scenario:'cross-surface-admin-applied'}).then(order=>{expect(order.tax).to.equal(0);expect(order.packages.some(p=>p.certificate_id===certificateId)).to.equal(true);});status(id,'cancelled');
    });
    task('remoteCertificates',{actorId}).then(rows=>expect(rows.filter(x=>x.certificateId===certificateId),'same saved certificate, no duplicate creation from recalculation').to.have.length(1));
   });
   for(const layout of ['classic','blocks'])for(const role of ['buyer','guest'])runIt(`[CERT-CHECKOUT-${layout.toUpperCase()}] ${role} submits and applies a new certificate on an actual order`,function(){
    prepareCart(site,{layout,role});if(layout==='classic')fillClassic('');else fillBlocks('');
    if(role==='guest')cy.get('body').then($body=>{if(!$body.find(layout==='classic'?'#certificate_id':'#exemption-certificate-input').length){cy.screenshot(`guest-certificate-unavailable-${layout}`);cy.task('qaResult',{id:`CERT-CHECKOUT-${layout.toUpperCase()}-GUEST`,status:'blocked',profile,version,reason:'Guest certificate creation unavailable in the deployed checkout UI; no creation pass claimed.'},{log:false}).then(()=>this.skip());}});
    cy.get(layout==='classic'?'#certificate_id':'#exemption-certificate-input').select('new',{force:true});certificateFields({layout,version});
    cy.get('#single_purchase').check({force:true});
    placeOrder(layout).then(id=>{
     inspectOrder(id,{profile,version,role,layout,scenario:'new-checkout-certificate'}).then(order=>{
      expect(order.customerId).to.equal(role==='guest'?0:site.userIds[1]);
      expect(order.packages).not.to.be.empty;order.packages.forEach(p=>{expect(p.certificate_id,'persisted newly created certificate').to.be.a('string').and.not.to.equal('').and.not.to.equal('new');});expect(order.tax).to.equal(0);
      task('remoteCertificates',{actorId:order.customerId}).then(rows=>{const mapping=singlePurchaseMapping(order,rows);cy.task('qaResult',{id:'single-purchase-certificate-mapping',profile,version,...mapping},{log:false});expect(mapping.reason,'checkout Resale reason persisted remotely').to.equal('Resale');});
     });
     status(id,'completed');inspectOrder(id,{profile,version,role,layout,scenario:'exempt-captured'}).then(order=>{expect(order.taxcloudStatus).to.equal('captured');if(version==='v3')verifyRemoteCapture(order,{profile,version,role,layout});});
    });
   });
   runIt('[CERT-POSITIVE-TAX] saved WA certificate removes a positive live quote and persists on capture',()=>{
    let certificateId,beforeTotal;
    task('remoteCertificates',{actorId:site.userIds[1]}).then(rows=>{const existing=rows.find(x=>!x.singlePurchase&&!x.disabledAt&&(x.states||[]).some(s=>s.abbreviation==='WA'));if(existing)certificateId=existing.certificateId;else createManagement(site,'myaccount',version,{state:'WA'}).then(id=>{certificateId=id;});});
    prepareCart(site,{product:'physical'});fillClassic('',{state:'WA',city:'Redmond',address:'1 Microsoft Way',postcode:'98052'});
    cy.get('.woocommerce-shipping-methods input[value^="local_pickup"]').check({force:true});cy.get('.blockUI',{timeout:90000}).should('not.exist');
    cy.get('#order_review .tax-rate .amount, #order_review .tax-total .amount').invoke('text').then(text=>expect(money(text),'unexempt live quote is positive').to.be.greaterThan(0));
    cy.get('#order_review .order-total .amount').invoke('text').then(text=>{beforeTotal=money(text);});
    cy.intercept('POST','**/*wc-ajax=update_order_review*').as('exemptionReview');
    cy.then(()=>cy.get('#certificate_id').select(certificateId,{force:true}));cy.wait('@exemptionReview',{timeout:90000}).its('response.statusCode').should('equal',200);cy.get('.blockUI',{timeout:90000}).should('not.exist');
    cy.get('#order_review .order-total .amount').should($amount=>expect(money($amount.text())).to.be.lessThan(beforeTotal));
    placeOrder('classic').then(id=>{
     inspectOrder(id,{profile,version,scenario:'positive-tax-exemption-applied'}).then(order=>{expect(order.tax).to.equal(0);expect(order.packages.some(p=>p.certificate_id===certificateId)).to.equal(true);});
     status(id,'completed');inspectOrder(id,{profile,version,scenario:'positive-tax-exempt-captured'}).then(order=>expect(order.taxcloudStatus).to.equal('captured'));
    });
   });
  }else runIt('[CERT-DI-UNAVAILABLE] Data Import hides unsupported certificate creation on account and both checkouts',()=>{
   cy.qaLogin('buyer');cy.visit(site.pages.myAccount);cy.get('.woocommerce-MyAccount-navigation').should('not.contain.text','Exemption Certificates');
   for(const layout of ['classic','blocks']){prepareCart(site,{layout});cy.get('#certificate_id,#exemption-certificate-input,.sst-certificate-add').should('not.exist');}
  });
 });
}
