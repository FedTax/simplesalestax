import {createManagement} from '../certificate-workflows';
import {matchesTitle} from '../selection';
import {task,configure,reserveOrder,inspectOrder,status,refund,refundLines,verifyRemoteCapture,prepareCart,fillClassic,placeOrder} from '../workflows';
const runIt=(title,fn)=>{if(matchesTitle(title,Cypress.env('qaCase')))it(title,fn);};
import {adminOrder,recalculate,saveOrder,openAddress} from '../admin-workflows';
for(const profile of ['realtime','dataImport'])for(const version of ['v1','v3']){
 if(Cypress.env('qaGroup')&&Cypress.env('qaGroup')!==`${profile}/${version}`)continue;
 describe(`admin ${profile}/${version}`,()=>{
  let site;
  before(()=>{cy.task('qaSite',null,{log:false}).then(x=>{site=x;});configure(profile,version);});
  for(const role of ['guest','buyer'])runIt(`[ADM-ORD-${role==='guest'?'01':'02'}] creates, recalculates, captures and partially refunds an admin ${role} order`,()=>{
   adminOrder(site,role).then(id=>{
    inspectOrder(id,{profile,version,role,scenario:'admin-pending'}).then(order=>{
     expect(order.customerId).to.equal(role==='guest'?0:site.userIds[1]);expect(order.taxcloudStatus).not.to.equal('captured');expect(order.total).to.be.at.least(22);
     cy.get('#woocommerce-order-items .wc-order-totals').should('contain.text',order.total.toFixed(2));
    });
    cy.reload();inspectOrder(id,{profile,version,role,scenario:'admin-reloaded'});
    status(id,'completed');inspectOrder(id,{profile,version,role,scenario:'admin-captured'}).then(order=>{
     expect(order.taxcloudStatus).to.equal('captured');if(version==='v3'||profile==='dataImport')verifyRemoteCapture(order,{profile,version,role});
    });
    refund(id,1);inspectOrder(id,{profile,version,scenario:'admin-partial-refund'}).then(order=>{expect(order.taxcloudStatus).to.equal('partially_refunded');expect(order.refundIds).to.have.length(1);});
    refund(id,1);inspectOrder(id,{profile,version,scenario:'admin-second-item-refund'}).then(order=>{expect(order.taxcloudStatus).to.equal('partially_refunded');expect(order.refundIds).to.have.length(2);});
    refundLines(id,{fee:true});inspectOrder(id,{profile,version,scenario:'admin-full-refund'}).then(order=>{expect(order.taxcloudStatus).to.equal('refunded');expect(order.refunded).to.equal(order.total);expect(order.refundIds).to.have.length(3);});
   });
  });
  if(profile==='realtime')runIt('[ADM-ORD-EXEMPT] applies and removes a saved customer certificate without creating a duplicate',()=>{
   let certificateId;
   task('remoteCertificates',{actorId:site.userIds[1]}).then(rows=>{
    const existing=rows.find(x=>!x.singlePurchase&&!x.disabledAt&&(x.states||[]).some(s=>s.abbreviation==='MN'));
    if(existing)certificateId=existing.certificateId;
    else createManagement(site,'wp-customer',version).then(id=>{certificateId=id;});
   });
   adminOrder(site,'buyer',{quantity:1}).then(id=>{
    cy.then(()=>cy.get('#exempt_cert').select(certificateId,{force:true}));recalculate();saveOrder();cy.reload();
    inspectOrder(id,{profile,version,scenario:'admin-saved-certificate'}).then(order=>{expect(order.tax).to.equal(0);expect(order.packages.some(p=>p.certificate_id===certificateId)).to.equal(true);});
    cy.get('#exempt_cert').select('',{force:true});recalculate();saveOrder();cy.reload();
    inspectOrder(id,{profile,version,scenario:'admin-certificate-removed'}).then(order=>order.packages.forEach(p=>expect(p.certificate_id||'').to.equal('')));
    task('remoteCertificates',{actorId:site.userIds[1]}).then(rows=>expect(rows.filter(x=>x.certificateId===certificateId)).to.have.length(1));
    status(id,'cancelled');
   });
  });
  for(const [kind,selection] of [['shipping-only',{shipping:true}],['fee-only',{fee:true}],['mixed',{quantity:1,shipping:true,fee:true}],['full',{quantity:2,shipping:true,fee:true}]])runIt(`[REF-ADMIN-${kind}] refunds storefront item/shipping/fee allocations through manual refund UI`,()=>{
   task('fee',{fee:'positive'});prepareCart(site,{product:'physical',quantity:2});fillClassic('');
   cy.get('.woocommerce-shipping-methods input[value^="flat_rate"]').check({force:true});cy.get('.blockUI',{timeout:90000}).should('not.exist');
   placeOrder('classic').then(id=>{
    status(id,'completed');inspectOrder(id,{profile,version,scenario:`${kind}-before-refund`}).then(order=>{expect(order.taxcloudStatus).to.equal('captured');expect(order.lines.some(x=>x.type==='shipping'&&x.total===5)).to.equal(true);expect(order.lines.some(x=>x.type==='fee'&&x.total===2)).to.equal(true);});
    refundLines(id,selection);inspectOrder(id,{profile,version,scenario:`${kind}-refunded`}).then(order=>{expect(order.refundIds).to.have.length(1);expect(order.refunded).to.be.greaterThan(0);if(kind==='full'){expect(order.refunded).to.equal(order.total);expect(order.taxcloudStatus).to.equal('refunded');}else expect(order.refunded).to.be.lessThan(order.total);});
   });
   task('fee',{fee:'none'});
  });
  runIt('[ADM-ORD-DRAFT] saves a named admin draft without capturing and then cancels it',()=>{
   adminOrder(site,'guest',{initialStatus:'Draft',addProducts:false}).then(id=>{
    cy.reload();inspectOrder(id,{profile,version,scenario:'admin-saved-draft'}).then(order=>{expect(order.status).to.match(/draft/);expect(order.taxcloudStatus).not.to.equal('captured');expect(order.remoteId).to.equal('');});
    status(id,'cancelled');inspectOrder(id,{profile,version,scenario:'admin-draft-cancelled'}).then(order=>expect(order.taxcloudStatus).not.to.equal('captured'));
   });
  });
  runIt('[ADM-ORD-03-06] saves and edits an uncaptured admin draft then cancels without capture',()=>{
   adminOrder(site,'guest',{quantity:1,productId:site.products.small}).then(id=>{
    openAddress('billing',0);cy.get('#_billing_city').clear().type('Portland');cy.get('#_billing_state').select('OR',{force:true});cy.get('#_billing_postcode').clear().type('97201');openAddress('shipping',1);cy.get('#_shipping_state').select('OR',{force:true});cy.get('#_shipping_city').clear().type('Portland');cy.get('#_shipping_postcode').clear().type('97201');
    cy.get('#order_line_items tr.item .edit-order-item').first().click();cy.get('#order_line_items input.quantity').first().clear().type('3').blur();
    cy.get('#woocommerce-order-items button.save-action').click();cy.get('#woocommerce-order-items .blockUI',{timeout:90000}).should('not.exist');recalculate();saveOrder();cy.reload();
    inspectOrder(id,{profile,version,scenario:'admin-edited-uncaptured'}).then(order=>{expect(order.taxcloudStatus).not.to.equal('captured');expect(order.tax).to.equal(0);expect(order.lines.find(x=>x.type==='line_item').quantity).to.equal(3);});
    status(id,'cancelled');inspectOrder(id,{profile,version,scenario:'admin-cancelled'}).then(order=>{expect(order.status).to.equal('cancelled');expect(order.taxcloudStatus).not.to.equal('captured');});
   });
  });
 });
}
