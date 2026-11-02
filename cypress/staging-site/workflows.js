import {captureIntentRequired} from './mutation-policy';
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
  cy.task('qaConnectionBoundary',null,{log:false});
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
  selectFirstOrigin();
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
export function reserveOrder(surface='checkout',role='buyer'){
  return cy.then(()=>{
    const number=Number(Cypress.env('qaNameCounter')||0)+1;Cypress.env('qaNameCounter',number);
    const key=`${Cypress.spec.name}:${Cypress.currentTest.titlePath.join(' / ')}:${number}:${surface}:${role}`;
    return task('reserveName',{key,surface,role}).then(name=>{
      Cypress.env('qaOrderName',name);cy.setCookie('sst_qa_order',name.token,{log:false});
      return cy.task('qaResult',{id:'NAME-reservation',label:name.label,surface,role,key},{log:false});
    });
  });
}
export function prepareCart(site,{layout='classic',role='buyer',quantity=1,product='virtual'}={}){
  task('layout',{layout});
  reserveOrder(layout,role);
  if(role==='guest'){
    // The Twenty Twenty-Five homepage's navigation view script rejects a transition
    // during Cypress's initial visit; begin on the owned cart route for guest setup.
    cy.clearAllCookies({log:false});cy.visit(site.pages.cartClassic);cy.task('qaLease',null,{log:false}).then(token=>cy.setCookie('sst_qa_run',token,{log:false}));cy.then(()=>cy.setCookie('sst_qa_order',Cypress.env('qaOrderName').token,{log:false}));
  }else cy.qaLogin(role);
  emptyCart(site);
  cy.visit(`?add-to-cart=${site.products[product]}&quantity=${quantity}`,{retryOnNetworkFailure:false});
  cy.visit(layout==='classic'?site.pages.cartClassic:site.pages.cartBlock);
  cy.get('a.checkout-button,a.wc-block-cart__submit-button').first().should('have.attr','href',layout==='classic'?site.pages.checkoutClassic:site.pages.checkoutBlock);
  cy.visit(layout==='classic'?site.pages.checkoutClassic:site.pages.checkoutBlock);
}
export function fillClassic(email,{state='MN',city='Minneapolis',address='323 Washington Ave N',postcode='55401'}={}){
 cy.then(()=>{
  const name=Cypress.env('qaOrderName');
  const values={billing_first_name:name.firstName,billing_last_name:name.lastName,billing_address_1:address,billing_city:city,billing_postcode:postcode,billing_phone:'6125550100',billing_email:name.email};
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
  cy.get('#billing_state').then($el=>{if($el.val()!==state)cy.get('#billing_state').select(state,{force:true});});
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
 });
}
export function fillBlocks(email){
 cy.then(()=>{
  const name=Cypress.env('qaOrderName');email=name.email;
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
    const prefix=$body.find('#shipping-first_name').length?'shipping':'billing';
    cy.get(`#${prefix}-first_name`).then($field=>{if(!Cypress.dom.isVisible($field))cy.wrap($field).closest('.wc-block-components-address-address-wrapper').find('.wc-block-components-address-card__edit').click();});
    const country=`#${prefix}-country`;if($body.find(country).length)cy.get(country).then($el=>{if($el.val()!=='US')cy.get(country).select('US',{force:true});});
    const values={'first_name':name.firstName,'last_name':name.lastName,'address_1':'323 Washington Ave N','city':'Minneapolis','postcode':'55401'};
    Object.entries(values).forEach(([name,value])=>cy.get(`#${prefix}-${name}`).then($el=>{if($el.val()!==value){cy.get(`#${prefix}-${name}`).clear();cy.get(`#${prefix}-${name}`).type(value,{delay:0});cy.get(`#${prefix}-${name}`).blur({force:true});}}));
    cy.get(`#${prefix}-state`).then($state=>{if($state.is('select')){if($state.val()!=='MN')cy.wrap($state).select('MN',{force:true});}else if($state.val()!=='Minnesota')cy.wrap($state).clear().type('Minnesota',{delay:0}).blur({force:true});});
    if($body.find(`#${prefix}-phone`).length)cy.get(`#${prefix}-phone`).then($el=>{if($el.val()!=='6125550100')cy.get(`#${prefix}-phone`).clear().type('6125550100',{delay:0}).blur({force:true});});
  });
  cy.get('input[value="cheque"]').check({force:true});
  cy.get('.wc-block-components-checkout-place-order-button').should('not.be.disabled');
 });
}
export function placeOrder(layout,{doubleClick=false}={}){
  const button=cy.get(layout==='classic'?'#place_order':'.wc-block-components-checkout-place-order-button');
  if(doubleClick)button.dblclick();else button.click();
  return cy.location('pathname',{timeout:90000}).should('include','order-received').then(path=>{
    const id=Number(path.match(/order-received\/(\d+)/)[1]);
    expect(path,'confirmation uses the assigned fixed checkout').to.include(layout==='classic'?'/classic-checkout/':'/block-checkout/');
    return cy.contains(Cypress.env('qaOrderName').label).should('be.visible').then(()=>id);
  });
}
export function inspectOrder(id,context={}){
  return task('order',{id}).then(order=>{
    expect(order.billingName,'persisted sequential customer name').to.equal(order.nameLabel);
    expect(order.nameLabel).to.match(/^Txc User [1-9]\d*$/);
    cy.get('body').should('contain.text',order.nameLabel);
    return cy.task('qaOrderEvidence',{...context,...order},{log:false}).then(()=>order);
  });
}
export function verifyRemoteCapture(order,context={}){
  return task('remoteOrder',{id:order.id}).then(remote=>{
    const evidence=singlePackageCaptureEvidence(remote,order);
    return cy.task('qaResult',{...context,id:'remote-capture-readback',orderId:order.id,...evidence},{log:false});
  });
}
export function status(id,value){
  let intent;
  task('info').then(info=>task('order',{id}).then(order=>{
    if(captureIntentRequired(info.settings,order,value)){
      cy.task('qaMutationIntent',{id,kind:'capture'},{log:false}).then(key=>{intent=key;});
    }
  }));
  cy.qaLogin('admin');task('order',{id}).then(order=>cy.visit(order.editUrl));
  cy.get('#order_status').select(`wc-${value}`,{force:true});cy.get('#save-order,button.save_order').first().click();
  cy.get('#order_status',{timeout:90000}).should('have.value',`wc-${value}`);
  task('order',{id}).then(order=>{if(intent&&order.taxcloudStatus==='captured')cy.task('qaAwaitDashboard',{id,phase:'capture'},{log:false,timeout:660000}).then(proof=>{cy.task('qaMutationVerified',{key:intent,evidence:'Exact capture outcome is known; see dashboard checkpoint for identity/financial findings.'},{log:false});cy.then(()=>{expect(proof.completedMatches,'dashboard shows persisted Completed status').to.equal(true);expect(proof.noReturns,'new capture has no existing returns').to.equal(true);expect(proof.customerMatches,'remote order customer matches the saved WooCommerce customer').to.equal(true);expect(proof.certificateMatches,'remote exemption matches the saved order').to.equal(true);expect(proof.lineItemsMatch,'remote item allocation matches the saved order').to.equal(true);});});});
}
export function refund(id,quantity){
  let intent;
  task('order',{id}).then(order=>{if(order.refunded===0)cy.task('qaAwaitDashboard',{id,phase:'capture'},{log:false,timeout:660000});});
  cy.qaLogin('admin');task('order',{id}).then(order=>cy.visit(order.editUrl));
  cy.on('window:confirm',()=>true);
  cy.get('button.refund-items').click();cy.get('input.refund_order_item_qty').first().clear().type(String(quantity)).blur();
  cy.intercept('POST','**/wp-admin/admin-ajax.php',req=>{if(String(req.body).includes('action=woocommerce_refund_line_items'))req.alias='manualRefund';});
  cy.task('qaMutationIntent',{id,kind:'refund'},{log:false}).then(key=>{intent=key;});
  cy.get('button.do-manual-refund').click();cy.wait('@manualRefund',{timeout:90000}).its('response.body.success').should('equal',true);
  cy.task('qaAwaitDashboard',{id,phase:'return'},{log:false,timeout:660000}).then(()=>cy.task('qaMutationVerified',{key:intent,evidence:'Matching persisted dashboard Returns checkpoint.'},{log:false}));
}

// Real manual refund form; exact shipping/fee tax values come from the saved item inputs.
export function refundLines(id,{quantity=0,shipping=false,fee=false}={}){
  let intent;
  task('order',{id}).then(order=>{if(order.refunded===0)cy.task('qaAwaitDashboard',{id,phase:'capture'},{log:false,timeout:660000});});
  cy.qaLogin('admin');task('order',{id}).then(order=>cy.visit(order.editUrl));cy.on('window:confirm',()=>true);
  cy.get('button.refund-items').click();
  if(quantity)cy.get('input.refund_order_item_qty').first().clear().type(String(quantity)).blur();
  for(const [type,enabled] of [['shipping',shipping],['fee',fee]])if(enabled){
    cy.get(`#order_${type==='shipping'?'shipping':'fee'}_line_items tr.${type}`).each($row=>{
      const total=$row.find('.edit input.line_total').val();
      cy.wrap($row).find('input.refund_line_total').clear().type(String(total)).blur();
      for(const input of $row.find('input.refund_line_tax').toArray()){
        const $tax=Cypress.$(input);const rate=$tax.attr('data-tax_id');const saved=$row.find(`.edit input[name$="[${rate}]"]`).val()||'0';
        cy.wrap($tax).clear().type(String(saved)).blur();
      }
    });
  }
  cy.intercept('POST','**/wp-admin/admin-ajax.php',req=>{if(String(req.body).includes('action=woocommerce_refund_line_items'))req.alias='allocationRefund';});
  cy.task('qaMutationIntent',{id,kind:'refund'},{log:false}).then(key=>{intent=key;});
  cy.get('button.do-manual-refund').click();cy.wait('@allocationRefund',{timeout:90000}).its('response.body.success').should('equal',true);
  cy.task('qaAwaitDashboard',{id,phase:'return'},{log:false,timeout:660000}).then(()=>cy.task('qaMutationVerified',{key:intent,evidence:'Matching persisted dashboard Returns checkpoint.'},{log:false}));
}
