const {singlePackageCaptureEvidence}=require('./remote-capture');
export const task=(action,data={})=>cy.task('qa',{action,...data},{log:false});
export const settingsPath='wp-admin/admin.php?page=wc-settings&tab=integration&section=wootax';
export const money=text=>Number(text.replace(/[^\d.-]/g,''));
export const saveSettings=()=>{
  cy.get('button[name="save"]').then($button=>{
    // WooCommerce disables Save until a change event; selecting an unchanged first origin still needs a second save.
    if($button.prop('disabled'))cy.get('form#mainform select').first().trigger('change',{force:true});
  });
  cy.get('button[name="save"]').should('be.enabled').click();
  cy.contains('.notice, .updated','saved',{matchCase:false,timeout:90000}).should('exist');
};
export function selectFirstOrigin(){
  cy.get('#woocommerce_wootax_default_origin_addresses').find('option').then($options=>{
    const option=[...$options].find(x=>x.value && !x.disabled);
    expect(Boolean(option),'a selectable origin exists').to.equal(true);
    const id=option.value;
    cy.get('#woocommerce_wootax_default_origin_addresses').select([id],{force:true});
    saveSettings();cy.reload();
    cy.get('#woocommerce_wootax_default_origin_addresses').should($el=>expect([].concat($el.val()||[]),'origin persisted after second save').to.include(id));
  });
}
export function configure(profile,version){
  cy.qaLogin('admin');cy.visit(settingsPath);
  cy.get('#woocommerce_wootax_tc_id',{log:false}).should('have.css','-webkit-text-security','disc');
  cy.get('#woocommerce_wootax_tc_key',{log:false}).should('have.css','-webkit-text-security','disc');
  cy.task('qaResult',{id:'credential-fields-masked',profile,version},{log:false});
  cy.task('qaCredentials',profile,{log:false}).then(pair=>{
    cy.get('#woocommerce_wootax_tc_id',{log:false}).clear({log:false}).type(pair.login,{log:false,parseSpecialCharSequences:false});
    cy.get('#woocommerce_wootax_tc_key',{log:false}).clear({log:false}).type(pair.key,{log:false,parseSpecialCharSequences:false});
    saveSettings();cy.reload();
    cy.get('#woocommerce_wootax_tc_id',{log:false}).should($el=>expect($el.val()===pair.login,'saved API login matches selected profile').to.equal(true));
    cy.get('#woocommerce_wootax_tc_key',{log:false}).should($el=>expect($el.val()===pair.key,'saved API key matches selected profile').to.equal(true));
  });
  cy.get('#woocommerce_wootax_default_origin_addresses').then($el=>cy.task('qaResult',{id:'origin-first-save',profile,version,originCleared:!($el.val()||[]).length},{log:false}));
  selectFirstOrigin();
  cy.get('#woocommerce_wootax_api_version').select(version,{force:true});saveSettings();cy.reload();
  cy.get('#woocommerce_wootax_api_version').should('have.value',version);
  cy.intercept('POST','**/wp-admin/admin-ajax.php',req=>{if(String(req.body).includes('sst_verify_taxcloud'))req.alias='verifyTaxCloud';});
  cy.once('window:alert',message=>expect(message,'Verify Settings alert').to.match(/success|valid/i));
  cy.get('#verifySettings').click();
  cy.wait('@verifyTaxCloud',{timeout:90000}).its('response.body.success').should('equal',true);
  // The helper observes actual management responses and the saved derived mode.
  cy.get('#verifySettings',{timeout:90000}).should('be.enabled');
  task('info').then(info=>{
    expect(info.settings.api_version).to.equal(version);
    expect(Boolean(info.settings.data_mover),'actual connection mode').to.equal(profile==='dataImport');
    expect(info.settings.default_origin_addresses).not.to.be.empty;
  });
}
export function emptyCart(site){
  cy.visit(site.pages.cartClassic);
  const removeNext=()=>cy.get('body').then($body=>{if($body.find('a.remove').length){cy.get('a.remove').first().click();cy.get('.blockUI',{timeout:90000}).should('not.exist');return removeNext();}});
  return removeNext();
}
export function prepareCart(site,{layout='classic',role='buyer',quantity=1,product='virtual'}={}){
  task('layout',{layout});
  if(role==='guest'){
    // The Twenty Twenty-Five homepage's navigation view script rejects a transition
    // during Cypress's initial visit; begin on the owned cart route for guest setup.
    cy.clearAllCookies({log:false});cy.visit(site.pages.cartClassic);cy.setCookie('sst_qa_run',Cypress.env('qaRunId'),{log:false});
  }else cy.qaLogin(role);
  emptyCart(site);
  cy.visit(`?add-to-cart=${site.products[product]}&quantity=${quantity}`,{retryOnNetworkFailure:false});
  cy.visit(layout==='classic'?site.pages.checkoutClassic:site.pages.checkoutBlock);
}
export function fillClassic(email){
  const values={billing_first_name:'Cypress',billing_last_name:'Buyer',billing_address_1:'323 Washington Ave N',billing_city:'Minneapolis',billing_postcode:'55401',billing_phone:'6125550100',billing_email:email};
  cy.get('.blockUI',{timeout:90000}).should('not.exist');
  cy.get('body').then($body=>{
    if($body.find('#certificate_id').length && $body.find('#certificate_id').val()!=='none'){
      cy.get('#certificate_id').select('none',{force:true}).should('have.value','none');
      cy.get('#exempt_certificate_form').should('not.be.visible');
      // WooCommerce may coalesce a selection-only update. The checkout POST and saved package below verify the cleared value.
      cy.get('.blockUI',{timeout:90000}).should('not.exist');
    }
  });
  cy.get('#billing_country').then($el=>{if($el.val()!=='US')cy.get('#billing_country').select('US',{force:true});});
  cy.get('#billing_state').then($el=>{if($el.val()!=='MN')cy.get('#billing_state').select('MN',{force:true});});
  Object.entries(values).forEach(([id,value])=>{
    cy.get(`#${id}`).then($el=>{
      if($el.val()!==value){
        cy.get(`#${id}`).clear();cy.get(`#${id}`).type(value,{delay:0});
        // Checkout AJAX replaces field nodes. Re-query rather than blurring a detached subject.
        cy.get(`#${id}`).blur();cy.get('.blockUI',{timeout:90000}).should('not.exist');
      }
    });
  });
  cy.get('.blockUI',{timeout:90000}).should('not.exist');cy.get('#payment_method_cheque').check({force:true});
}
export function fillBlocks(email){
  cy.get('.wc-block-checkout').should('be.visible');
  cy.get('body').then($body=>{
    if($body.find('#exemption-certificate-input').length && $body.find('#exemption-certificate-input').val()!==''){
      // WooCommerce 11 may submit extensionCartUpdate through the Store API batch endpoint.
      cy.intercept('POST',/\/wc\/store\/v1\/(?:cart\/extensions|batch)(?:[/?]|$)/).as('qaClearBlockCertificate');
      cy.get('#exemption-certificate-input').select('',{force:true});
      cy.wait('@qaClearBlockCertificate',{timeout:90000}).then(({response})=>{
        expect(response.statusCode).to.be.within(200,299);
        for(const part of response.body?.responses||[])expect(part.status).to.be.within(200,299);
      });
    }
  });
  cy.get('input[type="email"]').first().then($el=>{
    if(!$el.val()||!$el[0].checkValidity()){
      cy.get('input[type="email"]').first().clear();
      cy.get('input[type="email"]').first().type(email,{delay:0});
    }
  });
  cy.get('input[type="email"]').first().should($el=>expect($el[0].checkValidity(),'valid checkout email').to.equal(true));
  cy.get('input[type="email"]').first().blur({force:true});
  cy.get('body').then($body=>{
    const prefix=$body.find('#billing-first_name').length?'billing':'shipping';
    const country=`#${prefix}-country`;if($body.find(country).length)cy.get(country).then($el=>{if($el.val()!=='US')cy.get(country).select('US',{force:true});});
    const values={'first_name':'Cypress','last_name':'Buyer','address_1':'323 Washington Ave N','city':'Minneapolis','postcode':'55401'};
    Object.entries(values).forEach(([name,value])=>cy.get(`#${prefix}-${name}`).then($el=>{if($el.val()!==value){cy.get(`#${prefix}-${name}`).clear();cy.get(`#${prefix}-${name}`).type(value,{delay:0});cy.get(`#${prefix}-${name}`).blur({force:true});}}));
    cy.get(`#${prefix}-state`).then($state=>{if($state.is('select')){if($state.val()!=='MN')cy.wrap($state).select('MN',{force:true});}else if($state.val()!=='Minnesota')cy.wrap($state).clear().type('Minnesota',{delay:0}).blur({force:true});});
    if($body.find(`#${prefix}-phone`).length)cy.get(`#${prefix}-phone`).then($el=>{if($el.val()!=='6125550100')cy.get(`#${prefix}-phone`).clear().type('6125550100',{delay:0}).blur({force:true});});
  });
  cy.get('input[value="cheque"]').check({force:true});
  cy.get('.wc-block-components-checkout-place-order-button').should('not.be.disabled');
}
export function placeOrder(layout){
  if(layout==='classic')cy.get('#place_order').click();else cy.get('.wc-block-components-checkout-place-order-button').click();
  return cy.location('pathname',{timeout:90000}).should('include','order-received').then(path=>Number(path.match(/order-received\/(\d+)/)[1]));
}
export function inspectOrder(id,context={}){
  return task('order',{id}).then(order=>cy.task('qaOrderEvidence',{...context,...order},{log:false}).then(()=>order));
}
export function verifyRemoteCapture(order,context={}){
  return task('remoteOrder',{id:order.id}).then(remote=>{
    const evidence=singlePackageCaptureEvidence(remote,order);
    return cy.task('qaResult',{...context,id:'remote-capture-readback',orderId:order.id,...evidence},{log:false});
  });
}
export function status(id,value){
  cy.qaLogin('admin');
  task('order',{id}).then(order=>cy.visit(order.editUrl));
  cy.get('#order_status').select(`wc-${value}`,{force:true});cy.get('#save-order,button.save_order').first().click();
  cy.get('#order_status',{timeout:90000}).should('have.value',`wc-${value}`);
}
export function refund(id,quantity){
  cy.qaLogin('admin');task('order',{id}).then(order=>cy.visit(order.editUrl));
  cy.on('window:confirm',()=>true);
  cy.get('button.refund-items').click();cy.get('input.refund_order_item_qty').first().clear().type(String(quantity)).blur();
  cy.intercept('POST','**/wp-admin/admin-ajax.php',req=>{if(String(req.body).includes('action=woocommerce_refund_line_items'))req.alias='manualRefund';});
  cy.get('button.do-manual-refund').click();cy.wait('@manualRefund',{timeout:90000}).its('response.body.success').should('equal',true);
}
