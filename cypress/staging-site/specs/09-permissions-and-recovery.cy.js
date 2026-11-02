import {matchesTitle} from '../selection';
import {task,configure,reserveOrder,prepareCart,fillClassic,fillBlocks,placeOrder,inspectOrder,status,refund} from '../workflows';
function nativeEnter(){for(const type of ['keyDown','keyUp'])cy.then(()=>Cypress.automation('remote:debugger:protocol',{command:'Input.dispatchKeyEvent',params:{type,key:'Enter',code:'Enter',windowsVirtualKeyCode:13,nativeVirtualKeyCode:13,...(type==='keyDown'?{text:'\r',unmodifiedText:'\r'}:{})}}));}
const runIt=(title,fn)=>{if(matchesTitle(title,Cypress.env('qaCase')))it(title,fn);};
for(const profile of ['realtime','dataImport'])for(const version of ['v1','v3']){
 if(Cypress.env('qaGroup')&&Cypress.env('qaGroup')!==`${profile}/${version}`)continue;
 describe(`permissions ${profile}/${version}`,()=>{
  let site;before(()=>{cy.task('qaSite',null,{log:false}).then(x=>{site=x;});configure(profile,version);});
  runIt('[REF-MYACCOUNT-01-04] discovers the customer refund/request workflow on an eligible owned order',function(){
   prepareCart(site);fillClassic('');placeOrder('classic').then(id=>{
    status(id,'completed');cy.qaLogin('buyer');cy.visit(new URL(`view-order/${id}/`,site.pages.myAccount).href);
    cy.get('.woocommerce-order-details').should('be.visible');
    cy.get('body').then($body=>{
     const controls=$body.find('a,button,input[type="submit"]').filter((_,el)=>/refund|return request|request return/i.test(el.textContent||el.value||''));
     if(!controls.length){
      cy.screenshot('customer-refund-workflow-unavailable');
      cy.task('qaResult',{id:'REF-MYACCOUNT-01-04',status:'blocked',reason:'requested customer workflow unavailable; no refund/return/request control on completed My Account order',profile,version,orderId:id},{log:false}).then(()=>this.skip());
     }else{
      cy.task('qaResult',{id:'REF-MYACCOUNT-01-04',status:'blocked',reason:'customer refund control discovered; workflow requires site-specific implementation before claiming a pass',profile,version,orderId:id,controls:[...controls].map(x=>(x.textContent||x.value||'').trim())},{log:false}).then(()=>this.skip());
     }
    });
   });
  });
  runIt('[REF-PERM-01-04] another customer and a signed-out visitor cannot see an order or admin refund controls',()=>{
   prepareCart(site);fillClassic('');placeOrder('classic').then(id=>{
    inspectOrder(id,{profile,version,scenario:'permission-owned-order'}).then(order=>{
     cy.qaLogin('exempt');cy.visit(new URL(`view-order/${id}/`,site.pages.myAccount).href,{failOnStatusCode:false});
     cy.contains(/invalid order|cannot view|not.*permission/i).should('be.visible');cy.get('button.refund-items,.order_details .order-total').should('not.exist');
     cy.visit(order.editUrl,{failOnStatusCode:false});cy.get('button.refund-items').should('not.exist');
     cy.clearAllCookies({log:false});cy.visit(new URL(`view-order/${id}/`,site.pages.myAccount).href);cy.get('.woocommerce-order-details').should('not.exist');cy.get('form.login').should('be.visible');
     // Server authorization checks supplement these UI assertions; they are not separate browser journey passes.
     cy.request({method:'POST',url:'?rest_route=/sst-qa/v1/control',body:{action:'order',runId:Cypress.env('qaRunId'),id},failOnStatusCode:false,log:false}).its('status').should('be.oneOf',[401,403]);
    });
   });
  });
  if(profile==='realtime')runIt('[CERT-PERM] another customer cannot view or manage the buyer certificate through profile/account screens',()=>{
   let ownedIds=[];
   task('remoteCertificates',{actorId:site.userIds[1]}).then(rows=>{ownedIds=rows.map(x=>String(x.certificateId));});
   cy.qaLogin('exempt');cy.visit(new URL('exemption-certificates/',site.pages.myAccount).href);cy.get('#sst-certificates').should('be.visible');
   cy.then(()=>{for(const id of ownedIds)cy.get(`#sst-certificates tr[data-id="${id}"]`).should('not.exist');});
   // Read-only server check supplements the actual account/profile navigation boundary.
   cy.window({log:false}).then(win=>{
    const data=win.SST_Certificate_Table_Data;
    expect(Boolean(data?.get_certificates_nonce),'normal customer certificate nonce').to.equal(true);
    cy.request({url:'wp-admin/admin-ajax.php',qs:{action:'sst_get_certificates',nonce:data.get_certificates_nonce,customerId:site.userIds[1]},log:false}).then(response=>{
     expect(response.status).to.equal(200);expect(Object.keys(response.body.data||{}),'foreign certificates withheld by server').to.have.length(0);
    });
   });
   cy.visit(`wp-admin/user-edit.php?user_id=${site.userIds[1]}`,{failOnStatusCode:false});cy.get('.sst-certificate-add,#sst-certificates').should('not.exist');
  });
  runIt('[NOTICE-EMAIL-01-02] shows order/refund outcomes and generates each supported notification once with delivery suppressed',()=>{
   let offset;task('records').then(rows=>{offset=rows.length;});prepareCart(site);fillClassic('');
   placeOrder('classic').then(id=>{
    cy.contains(/thank you|order received/i).should('be.visible');status(id,'completed');
    inspectOrder(id,{profile,version,scenario:'notification-captured'}).then(order=>expect(order.taxcloudStatus).to.equal('captured'));
    refund(id,1);inspectOrder(id,{profile,version,scenario:'notification-refunded'}).then(order=>expect(order.taxcloudStatus).to.equal('refunded'));
    cy.reload();cy.get('#sales_tax_meta').should('contain.text','Refunded');
    task('records').then(rows=>{
     const events=rows.slice(offset).filter(x=>x.transport==='mail-generation'&&x.orderId===id);
     for(const kind of ['new-order','completed','refund'])expect(events.filter(x=>x.notificationType===kind),`${kind} generated once`).to.have.length(1);
     expect(events.every(x=>x.suppressed)).to.equal(true);
     cy.task('qaResult',{id:'NOTICE-EMAIL-01-02',profile,version,orderId:id,notificationTypes:events.map(x=>x.notificationType),delivery:'suppressed; generation only, no delivery claim'},{log:false});
    });
   });
  });
  runIt('[MOBILE-FRONTEND] renders both reused checkouts and My Account at 390 by 844 with accessible controls',()=>{
   cy.viewport(390,844);
   for(const layout of ['classic','blocks']){
    prepareCart(site,{layout});if(layout==='classic')fillClassic('');else fillBlocks('');
    cy.get(layout==='classic'?'#place_order':'.wc-block-components-checkout-place-order-button').scrollIntoView().should('be.visible').and('not.be.disabled');
    cy.document().then(doc=>expect(doc.documentElement.scrollWidth,`${layout} document width`).to.be.at.most(doc.documentElement.clientWidth+1));
    cy.screenshot(`mobile-${layout}-checkout`);
   }
   cy.qaLogin('buyer');cy.visit(site.pages.myAccount);cy.get('.woocommerce-MyAccount-navigation').should('be.visible');
   if(profile==='realtime'){
    cy.visit(new URL('exemption-certificates/',site.pages.myAccount).href);cy.get('#sst-certificates').should('be.visible');
    cy.get('.sst-certificate-add').focus().should('have.focus');nativeEnter();cy.get('.wc-backbone-modal-content').should('be.visible');
    cy.get('.wc-backbone-modal .modal-close-link').focus().should('have.focus');nativeEnter();cy.get('.wc-backbone-modal').should('not.exist');
   }
   cy.document().then(doc=>expect(doc.documentElement.scrollWidth,'account document width').to.be.at.most(doc.documentElement.clientWidth+1));
  });
  runIt('[ORDER-ACCOUNT-01-04] preserves named order lists/details and supports pay-for-order and reorder where offered',()=>{
   prepareCart(site);fillClassic('');
   placeOrder('classic').then(id=>{
    let originalName,originalRemote;
    inspectOrder(id,{profile,version,scenario:'account-order-created'}).then(order=>{originalName=order.nameLabel;});
    cy.qaLogin('admin');cy.visit(site.hpos?'wp-admin/admin.php?page=wc-orders':'wp-admin/edit.php?post_type=shop_order');
    cy.contains('a.order-view',new RegExp(`#${id}\\b`)).should($link=>expect($link.text()).to.include(originalName));
    status(id,'pending');cy.qaLogin('buyer');cy.visit(new URL('orders/',site.pages.myAccount).href);
    cy.contains('tr',new RegExp(`#${id}\\b`)).within(()=>cy.contains('a','Pay').click());
    cy.location('pathname').should('include',`/classic-checkout/order-pay/${id}/`);
    cy.get('#payment_method_cheque').check({force:true});cy.get('#place_order').click();
    cy.location('pathname',{timeout:90000}).should('include',`/classic-checkout/order-received/${id}/`);
    task('order',{id}).then(order=>{expect(order.nameLabel).to.equal(originalName);expect(order.taxcloudStatus).not.to.equal('captured');});
    cy.visit(new URL(`view-order/${id}/`,site.pages.myAccount).href);cy.get('.woocommerce-customer-details').should($body=>expect($body.text()).to.include(originalName));
    cy.reload();cy.visit(new URL('orders/',site.pages.myAccount).href);cy.go('back');cy.location('pathname').should('include',`view-order/${id}/`);cy.go('forward');
    status(id,'completed');task('order',{id}).then(order=>{originalRemote=order.remoteId;});cy.qaLogin('buyer');cy.visit(new URL(`view-order/${id}/`,site.pages.myAccount).href);
    cy.get('body').then($body=>{
     if(!$body.find('.order-again a').length){cy.task('qaResult',{id:'ORDER-ACCOUNT-REORDER',profile,version,status:'not-applicable',reason:'No Order again control on the completed account order.'},{log:false});return;}
     reserveOrder('classic','buyer');cy.get('.order-again a').click();cy.visit(site.pages.checkoutClassic);fillClassic('');
     placeOrder('classic').then(second=>{
      expect(second).not.to.equal(id);inspectOrder(second,{profile,version,scenario:'reordered-new-order'}).then(order=>{expect(order.nameLabel).not.to.equal(originalName);order.packages.forEach(p=>expect(p.certificate_id||'').to.equal(''));});
      status(second,'completed');task('order',{id:second}).then(order=>expect(order.remoteId).not.to.equal(originalRemote));
      task('order',{id}).then(order=>{expect(order.nameLabel).to.equal(originalName);expect(order.remoteId).to.equal(originalRemote);cy.visit(order.editUrl);});
      cy.get('body').should($body=>expect($body.text()).to.include(originalName));
     });
    });
   });
  });
  runIt('[FLOW-RECOVERY-DOUBLE] double-clicks Place order and confirms one submitted order and one reservation binding',()=>{
   prepareCart(site,{role:'guest'});fillClassic('');let submissions=0;
   cy.intercept('POST','**/*wc-ajax=checkout*',()=>{submissions++;});
   placeOrder('classic',{doubleClick:true}).then(id=>{
    inspectOrder(id,{profile,version,scenario:'double-click-submission'});
    task('info').then(info=>{expect(info.names.filter(x=>x.label===Cypress.env('qaOrderName').label)).to.have.length(1);expect(info.names.find(x=>x.label===Cypress.env('qaOrderName').label).orderId).to.equal(id);expect(submissions,'one actual checkout POST').to.equal(1);});
   });
  });
  runIt('[FLOW-RECOVERY-01-03] corrects validation, reloads, and preserves one named order after profile reset',()=>{
   prepareCart(site,{role:'guest'});cy.get('#billing_first_name').clear();cy.get('#place_order').click();
   cy.get('.woocommerce-error,.wc-block-components-notice-banner.is-error').should('be.visible');cy.location('pathname').should('not.include','order-received');
   fillClassic('');placeOrder('classic').then(id=>{
    inspectOrder(id,{profile,version,scenario:'validation-recovered'}).then(order=>{
     const name=order.billingName;cy.reload();
     cy.get('body').then($body=>{if($body.find('form.woocommerce-verify-email').length){
      cy.get('form.woocommerce-verify-email #email').type('wrong-qa@example.test');cy.get('button[name="verify"]').click();cy.contains(/unable to verify|try again/i).should('be.visible');
      cy.get('form.woocommerce-verify-email #email').clear().type(Cypress.env('qaOrderName').email);cy.get('button[name="verify"]').click();
     }});
     cy.contains(name).should('be.visible');cy.contains('323 Washington Ave N').should('be.visible');
     task('resetActors');inspectOrder(id,{profile,version,scenario:'name-after-baseline-reset'}).then(after=>expect(after.billingName).to.equal(name));
    });
   });
  });
 });
}
