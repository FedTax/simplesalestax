import {matchesTitle} from '../selection';
const runIt=(title,fn)=>{if(matchesTitle(title,Cypress.env('qaCase')))it(title,fn);};
import {task,configure,prepareCart,fillClassic,fillBlocks,placeOrder,inspectOrder,verifyRemoteCapture,status,refund,money,settingsPath,saveSettings,selectFirstOrigin,emptyCart} from '../workflows';
for(const profile of ['realtime','dataImport'])for(const version of ['v1','v3']){
 if(Cypress.env('qaGroup') && Cypress.env('qaGroup')!==`${profile}/${version}`)continue;
 describe(`${profile}/${version}`,()=>{
  let site;
  before(()=>{cy.task('qaSite',null,{log:false}).then(value=>{site=value;});configure(profile,version);});
  runIt('settings have a persisted origin and the expected API and connection mode',()=>{
    cy.qaLogin('admin');cy.visit(settingsPath);cy.get('#woocommerce_wootax_api_version').should('have.value',version);
    task('info').then(info=>{expect(Boolean(info.settings.data_mover)).to.equal(profile==='dataImport');expect(info.settings.default_origin_addresses).not.to.be.empty;});
    if(profile==='dataImport'){
      cy.get('#woocommerce_wootax_show_exempt').should('be.disabled');cy.get('#woocommerce_wootax_enable_taxcloud_rate_limit').should('be.disabled');
    }
  });
  runIt('product TIC search saves and reloads the selected product code',()=>{
    cy.qaLogin('admin');cy.visit(`wp-admin/post.php?post=${site.products.virtual}&action=edit`);
    cy.get('.sst-select-tic').first().click();cy.get('.sst-tic-search').clear().type('Uncategorized');
    cy.contains('.tic-row','Uncategorized',{timeout:60000}).find('button').click();cy.get('#publish').click();
    cy.contains('Product updated').should('exist');cy.get('.sst-tic-input').first().should('have.value','00000');
  });
  for(const layout of ['classic','blocks'])for(const role of ['buyer','guest']){
    runIt(`${layout} ${role} places a real order with matching visible/persisted totals`,()=>{
      prepareCart(site,{layout,role,quantity:layout==='blocks'&&role==='buyer'?2:1});
      // A buyer can abandon a new certificate and still place a normal order.
      if(profile==='realtime'&&role==='buyer'){
        cy.get(layout==='classic'?'#certificate_id':'#exemption-certificate-input').select('new',{force:true});
        cy.get('#purchaser_exemption_reason').should('be.visible');
      }
      const email=`qa-${Cypress.env('qaRunId').slice(0,8)}-${role}@example.test`;
      if(layout==='classic')fillClassic(email);else fillBlocks(email);
      let shownTotal;
      const totalSelector=layout==='classic'?'#order_review .order-total .amount':'.wc-block-components-totals-footer-item .wc-block-components-totals-item__value:visible';
      cy.get(totalSelector).first().should('be.visible').invoke('text').then(text=>{shownTotal=money(text);});
      placeOrder(layout).then(id=>{
        inspectOrder(id,{profile,version,layout,role,scenario:'core-order'}).then(order=>{
          expect(order.total,'UI and saved total').to.equal(shownTotal);expect(order.total).to.be.at.least(10);expect(order.tax).to.be.at.least(0);
          if(profile==='realtime'){
            expect(order.packages).to.be.an('array').and.not.be.empty;
            order.packages.forEach(pack=>{expect(pack.api_version||'v1').to.equal(version);expect(pack.certificate_id||'','ordinary order has no certificate').to.equal('');});
          }
          expect(order.taxcloudStatus,'no early capture for completed-only trigger').not.to.equal('captured');
        });
        status(id,'completed');
        inspectOrder(id,{profile,version,layout,role,scenario:'core-captured'}).then(order=>{
          expect(order.taxcloudStatus,'frontend-created order synchronizes on completion').to.equal('captured');
          if(version==='v3'||profile==='dataImport')verifyRemoteCapture(order,{profile,version,layout,role});
          if(role==='buyer'){cy.qaLogin('buyer');cy.visit(new URL(`view-order/${id}/`,site.pages.myAccount).href);cy.get('.woocommerce-order-details').should('be.visible');cy.get('.woocommerce-customer-details').should('contain.text',order.nameLabel);cy.reload();cy.get('.woocommerce-customer-details').should('contain.text',order.nameLabel);}
        });
      });
    });
  }
  runIt('completes once and synchronizes two separate one-unit refunds',()=>{
    prepareCart(site,{quantity:2});fillClassic(`qa-life-${Cypress.env('qaRunId').slice(0,8)}@example.test`);
    placeOrder('classic').then(id=>{
      status(id,'completed');
      inspectOrder(id,{profile,version,scenario:'capture'}).then(order=>{
        expect(order.taxcloudStatus).to.equal('captured');
        const total=order.total;
        status(id,'processing');status(id,'completed');
        inspectOrder(id,{profile,version,scenario:'repeat-completion'}).then(again=>{expect(again.packages).to.deep.equal(order.packages);expect(again.taxcloudStatus).to.equal('captured');});
        refund(id,1);
        inspectOrder(id,{profile,version,scenario:'partial-refund'}).then(partial=>{expect(partial.taxcloudStatus).to.equal('partially_refunded');expect(partial.refunded).to.be.greaterThan(0).and.lessThan(total);});
        refund(id,1);
        inspectOrder(id,{profile,version,scenario:'full-refund'}).then(full=>{expect(full.taxcloudStatus).to.equal('refunded');expect(full.refunded).to.equal(total);expect(full.refundIds).to.have.length(2);});
        if(version==='v3'||profile==='dataImport')task('remoteOrder',{id}).then(remote=>expect(remote).to.have.property('orderId'));
      });
    });
  });
  runIt('cancels an uncaptured real order without a capture or refund',()=>{
    prepareCart(site);fillClassic(`qa-cancel-${Cypress.env('qaRunId').slice(0,8)}@example.test`);
    placeOrder('classic').then(id=>{
      status(id,'cancelled');inspectOrder(id,{profile,version,scenario:'cancelled'}).then(order=>{expect(order.status).to.equal('cancelled');expect(order.taxcloudStatus).not.to.equal('captured');expect(order.refunded).to.equal(0);});
    });
  });
  runIt('changes quantity and removes all items without stale cart totals',()=>{
    task('layout',{layout:'classic'});cy.qaLogin('buyer');emptyCart(site);cy.visit(`?add-to-cart=${site.products.virtual}`);cy.visit(site.pages.cartClassic);
    cy.get('input.qty').first().clear().type('2');cy.get('button[name="update_cart"]').click();cy.get('.blockUI',{timeout:90000}).should('not.exist');
    cy.get('.cart-subtotal .amount').invoke('text').then(text=>expect(money(text)).to.equal(20));
    cy.get('a.remove').first().click();cy.contains(/cart is currently empty|cart is empty/i).should('be.visible');
    cy.reload();cy.contains(/cart is currently empty|cart is empty/i).should('be.visible');
  });
  runIt('applies and removes a real coupon without stale discounts',()=>{
    task('layout',{layout:'classic'});cy.qaLogin('buyer');emptyCart(site);cy.visit(`?add-to-cart=${site.products.virtual}`);cy.visit(site.pages.cartClassic);
    cy.get('.blockUI',{timeout:90000}).should('not.exist');cy.get('#coupon_code').clear().type(site.coupon,{delay:0});cy.get('#coupon_code').should('have.value',site.coupon);cy.get('button[name="apply_coupon"]').click();cy.get('.cart-discount').should('exist');
    cy.get('.woocommerce-remove-coupon').click();cy.get('.cart-discount').should('not.exist');
  });
  runIt('shows the expected exemption controls on classic and Blocks checkout',()=>{
    for(const layout of ['classic','blocks']){
      prepareCart(site,{layout});
      if(layout==='blocks')cy.window({log:false}).then(win=>{
        const data=win.wc?.wcSettings?.getSetting('simple-sales-tax_data',{})||{};
        return cy.task('qaResult',{id:'blocks-exemption-capability',profile,version,hasDescriptionFlag:Object.prototype.hasOwnProperty.call(data,'requireExemptionReasonDescription'),requiresDescription:data.requireExemptionReasonDescription??null},{log:false});
      });
      if(profile==='dataImport')cy.get(layout==='classic'?'#certificate_id':'#exemption-certificate-input').should('not.exist');
      else{
        const selector=layout==='classic'?'#certificate_id':'#exemption-certificate-input';
        cy.get(selector).select('new',{force:true});
        cy.get('#purchaser_exemption_reason').select('Resale',{force:true});
        if(version==='v3')cy.get('#purchaser_exemption_reason_value').should('be.visible');
        cy.get('#purchaser_exemption_reason').should('have.value','Resale');
      }
    }
  });
  runIt('renders the cart at a mobile viewport without document overflow',()=>{
    cy.viewport(390,844);task('layout',{layout:'classic'});cy.qaLogin('buyer');emptyCart(site);cy.visit(`?add-to-cart=${site.products.virtual}`);cy.visit(site.pages.cartClassic);
    cy.get('.cart_totals').should('be.visible');cy.document().then(doc=>expect(doc.documentElement.scrollWidth).to.be.at.most(doc.documentElement.clientWidth+1));
  });
 });
}
