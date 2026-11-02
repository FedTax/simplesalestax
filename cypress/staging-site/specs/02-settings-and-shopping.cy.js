import {matchesTitle} from '../selection';
const runIt=(title,fn)=>{if(matchesTitle(title,Cypress.env('qaCase')))it(title,fn);};
import {task,configure,prepareCart,fillClassic,placeOrder,inspectOrder,money,settingsPath,saveSettings,emptyCart,status,refund} from '../workflows';
function setFields(values){
  cy.qaLogin('admin');cy.visit(settingsPath);
  Object.entries(values).forEach(([name,value])=>cy.get(`#woocommerce_wootax_${name}`).then($el=>{
    if($el.is('select'))cy.wrap($el).select(value,{force:true});
    else if($el.is(':checkbox')){if(value)cy.wrap($el).check({force:true});else cy.wrap($el).uncheck({force:true});}
    else cy.wrap($el).clear().type(String(value));
  }));saveSettings();cy.reload();
}
for(const profile of ['realtime','dataImport'])for(const version of ['v1','v3']){
 if(Cypress.env('qaGroup')&&Cypress.env('qaGroup')!==`${profile}/${version}`)continue;
 describe(`extended ${profile}/${version}`,()=>{
 let site;
 before(()=>{cy.task('qaSite',null,{log:false}).then(x=>{site=x;});configure(profile,version);});
 after(()=>{setFields({disable_integration:'no',capture_orders_in_taxcloud:true,capture_trigger:'completed',tax_based_on:'item-price',enable_taxcloud_rate_limit:false});});
 runIt('persists a variation TIC override and supports selecting the variation on the storefront',()=>{
   cy.qaLogin('admin');cy.visit(`wp-admin/post.php?post=${site.products.variable}&action=edit`);
   cy.get('.variations_tab a').click();cy.get('.woocommerce_variation').first().find('h3').click();
   cy.get('.woocommerce_variation').first().find('.sst-select-tic').click();cy.get('.sst-tic-search').clear().type('Uncategorized');
   cy.contains('.tic-row','Uncategorized',{timeout:60000}).find('button').click();
   cy.get('button.save-variation-changes').click();cy.get('.blockUI',{timeout:90000}).should('not.exist');
   task('layout',{layout:'classic'});cy.qaLogin('buyer');emptyCart(site);cy.visit(`?p=${site.products.variable}`);
   cy.get('select[name="attribute_size"]').select('Small');
   cy.get('input.variation_id').should($el=>expect(Number($el.val()),'resolved selected variation').to.be.greaterThan(0));
   cy.get('.single_add_to_cart_button').should('not.have.class','disabled').click();cy.visit(site.pages.cartClassic);
   cy.get('.cart-subtotal .amount').invoke('text').then(text=>expect(money(text)).to.equal(15.15));
 });
 runIt('calculates a mixed physical/virtual cart and changes available shipping methods',()=>{
   prepareCart(site,{product:'physical'});cy.get('.blockUI',{timeout:90000}).should('not.exist');
   cy.visit(`${site.pages.cartClassic}?add-to-cart=${site.products.virtual}`,{retryOnNetworkFailure:false});cy.get('.woocommerce-cart-form .cart_item').should('have.length',2);
   // Drop the mutating add-to-cart query before testing persistence with a reload.
   cy.visit(site.pages.cartClassic);cy.reload();cy.get('.woocommerce-cart-form .cart_item').should('have.length',2);
   cy.visit(site.pages.checkoutClassic);fillClassic(`qa-shipping-${Cypress.env('qaRunId').slice(0,8)}@example.test`);
   cy.get('.woocommerce-shipping-methods input[type="radio"]').should('have.length.at.least',2);
   cy.get('.woocommerce-shipping-methods input[type="radio"]').last().check({force:true});cy.get('.blockUI',{timeout:90000}).should('not.exist');
   cy.get('#order_review .order-total .amount').should('be.visible');
   cy.get('#order_review .cart_item').should('have.length',2);let visible;
   cy.get('#order_review .order-total .amount').invoke('text').then(text=>{visible=money(text);});
   placeOrder('classic').then(id=>inspectOrder(id,{profile,version,scenario:'mixed-shipping'}).then(order=>{
     expect(order.lines.filter(x=>x.type==='line_item')).to.have.length(2);expect(order.lines.some(x=>x.type==='shipping')).to.equal(true);expect(order.total).to.equal(visible);
     expect(Number(order.lines.reduce((sum,line)=>sum+line.tax,0).toFixed(2))).to.equal(order.tax);
     if(profile==='realtime'){
       const pickup=order.packages.find(p=>p.shipping_method==='local_pickup');expect(pickup,'saved pickup package').to.exist;
       expect(pickup.destination_address).to.equal(pickup.origin_address);
       const virtual=order.packages.find(p=>p.cart_items.some(i=>i.id===site.products.virtual));expect(virtual.destination_address).to.include('Minneapolis');
     }
     return cy.task('qaResult',{id:'mixed-shipping-tax-evidence',profile,version,orderId:id,tax:order.tax,total:order.total,visibleTotal:visible,positiveTaxExercised:order.tax>0},{log:false});
   }));
 });
 if(profile==='realtime'&&version==='v3')runIt('captures and fully refunds a positive-tax local-pickup order',()=>{
   prepareCart(site,{product:'physical'});fillClassic(`qa-tax-refund-${Cypress.env('qaRunId').slice(0,8)}@example.test`);
   cy.get('.woocommerce-shipping-methods input[type="radio"]').last().invoke('val').should('match',/^local_pickup/);
   cy.get('.woocommerce-shipping-methods input[type="radio"]').last().check({force:true});
   cy.get('.blockUI',{timeout:90000}).should('not.exist');let visible;
   cy.get('#order_review .order-total .amount').invoke('text').then(text=>{visible=money(text);});
   placeOrder('classic').then(id=>inspectOrder(id,{profile,version,scenario:'positive-tax-pending'}).then(order=>{
     expect(order.tax,'pickup order should have positive sales tax').to.be.greaterThan(0);expect(order.total).to.equal(visible);
     status(id,'completed');
     inspectOrder(id,{profile,version,scenario:'positive-tax-captured'}).then(captured=>{
       expect(captured.taxcloudStatus).to.equal('captured');expect(captured.tax).to.be.greaterThan(0);
       cy.get('#sales_tax_meta').should('contain.text','Captured').scrollIntoView();cy.screenshot('positive-tax-captured',{capture:'viewport'});
       refund(id,1);inspectOrder(id,{profile,version,scenario:'positive-tax-refunded'}).then(refunded=>{
         expect(refunded.taxcloudStatus).to.equal('refunded');expect(refunded.refunded).to.equal(refunded.total);
         cy.reload();cy.get('#sales_tax_meta').should('contain.text','Refunded').scrollIntoView();cy.screenshot('positive-tax-refunded',{capture:'viewport'});
       });
     });
   }));
 });
 if(profile==='realtime'&&version==='v3'&&Cypress.env('qaExistingPositiveTaxOrder'))runIt('refunds the positive-tax order from an interrupted verification',()=>{
   const id=Number(Cypress.env('qaExistingPositiveTaxOrder'));
   inspectOrder(id,{profile,version,scenario:'positive-tax-refund-recovery-before'}).then(order=>{
     expect(order.tax).to.be.greaterThan(0);expect(order.taxcloudStatus).to.equal('captured');
     refund(id,1);inspectOrder(id,{profile,version,scenario:'positive-tax-refund-recovery-after'}).then(refunded=>{
       expect(refunded.taxcloudStatus).to.equal('refunded');expect(refunded.refunded).to.equal(refunded.total);
     });
   });
 });
 runIt('preserves the tax and total on a free/non-taxable mixed cart',()=>{
   prepareCart(site,{product:'nontaxable'});cy.visit(`?add-to-cart=${site.products.free}`);cy.visit(site.pages.checkoutClassic);fillClassic(`qa-nontax-${Cypress.env('qaRunId').slice(0,8)}@example.test`);
   placeOrder('classic').then(id=>inspectOrder(id,{profile,version,scenario:'nontaxable-free'}).then(order=>{expect(order.total).to.equal(5);expect(order.tax).to.equal(0);}));
 });
 runIt('shows required-field errors and recovers without placing a duplicate order',()=>{
   prepareCart(site,{role:'guest'});cy.get('#billing_first_name').clear();cy.get('#place_order').click();cy.get('.woocommerce-error, .wc-block-components-notice-banner.is-error').should('be.visible');cy.location('pathname').should('not.include','order-received');
   fillClassic(`qa-recover-${Cypress.env('qaRunId').slice(0,8)}@example.test`);placeOrder('classic').then(id=>inspectOrder(id,{profile,version,scenario:'form-validation-recovery'}));
 });
 runIt('disabling capture prevents completion from submitting to TaxCloud',()=>{
   setFields({capture_orders_in_taxcloud:false});
   prepareCart(site);fillClassic(`qa-nocapture-${Cypress.env('qaRunId').slice(0,8)}@example.test`);
   placeOrder('classic').then(id=>{status(id,'completed');inspectOrder(id,{profile,version,scenario:'capture-disabled'}).then(order=>expect(order.taxcloudStatus).not.to.equal('captured'));});
   setFields({capture_orders_in_taxcloud:true});
 });
 runIt('disabled integration leaves checkout usable and sends no lookup/capture',()=>{
   setFields({disable_integration:'yes'});let offset;
   task('records').then(rows=>{offset=rows.length;});prepareCart(site);fillClassic(`qa-disabled-${Cypress.env('qaRunId').slice(0,8)}@example.test`);
   placeOrder('classic').then(id=>{status(id,'completed');inspectOrder(id,{profile,version,scenario:'integration-disabled'}).then(order=>expect(order.taxcloudStatus).not.to.equal('captured'));});
   task('records').then(rows=>expect(rows.slice(offset).filter(x=>x.transport==='v1-attempt'||/\/carts|\/orders/.test(x.path))).to.be.empty);
   setFields({disable_integration:'no'});
 });
 if(profile==='realtime'){
   runIt('state/address changes clear stale tax and certificate selection',()=>{
     prepareCart(site);fillClassic(`qa-address-${Cypress.env('qaRunId').slice(0,8)}@example.test`);
     cy.get('#billing_state').select('OR',{force:true});cy.get('#billing_city').clear().type('Portland');cy.get('#billing_address_1').clear().type('1037 SW Broadway');cy.get('#billing_postcode').clear().type('97201').blur();cy.get('.blockUI',{timeout:90000}).should('not.exist');
     placeOrder('classic').then(id=>inspectOrder(id,{profile,version,scenario:'zero-tax-oregon'}).then(order=>expect(order.tax).to.equal(0)));
   });
   runIt('My Account certificate management opens, validates, refreshes and closes without submitting fabricated data',()=>{
     cy.qaLogin('buyer');cy.visit(new URL('edit-address/billing/',site.pages.myAccount).href);
     cy.get('#billing_country').select('US',{force:true});cy.get('#billing_state').select('MN',{force:true});
     for(const [field,value] of Object.entries({first_name:'Cypress',last_name:'Buyer',address_1:'323 Washington Ave N',city:'Minneapolis',postcode:'55401',phone:'6125550100',email:`qa-account-${Cypress.env('qaRunId').slice(0,8)}@example.test`}))cy.get(`#billing_${field}`).clear().type(value,{delay:0});
     cy.get('button[name="save_address"]').click();cy.contains(/address changed|address updated|address saved/i).should('exist');
     cy.visit(new URL('exemption-certificates/',site.pages.myAccount).href);
     cy.window({log:false}).then(win=>cy.task('qaResult',{id:'certificate-billing-completeness',version,hasTableData:Boolean(win.SST_Certificate_Table_Data),hasBillingAddress:Boolean(win.SST_Certificate_Table_Data?.billing_address),fields:Object.fromEntries(['first_name','last_name','address_1','country','city','state','postcode'].map(key=>[key,Boolean(win.SST_Certificate_Table_Data?.billing_address?.[key])]))},{log:false}));
     cy.get('.sst-certificate-add').click();
     // The outer wrapper has zero height because its visible dialog is position:fixed.
     cy.get('.wc-backbone-modal-content').should('be.visible');cy.get('#purchaser_exemption_reason').select('Resale',{force:true});cy.get('#btn-ok').click();cy.get('.woocommerce-invalid-required-field').should('exist');
     cy.get('.wc-backbone-modal .modal-close-link').click();cy.get('.wc-backbone-modal').should('not.exist');cy.get('.sst-certificate-refresh').click();cy.get('#sst-certificates').should('be.visible');
   });
   runIt('Line Subtotal calculation produces consistent order totals with fractional prices and quantity three',()=>{
     setFields({tax_based_on:'line-subtotal'});prepareCart(site,{quantity:3,product:'physical'});fillClassic(`qa-rounding-${Cypress.env('qaRunId').slice(0,8)}@example.test`);
     let shown;cy.get('#order_review .order-total .amount').invoke('text').then(t=>{shown=money(t);});
     placeOrder('classic').then(id=>inspectOrder(id,{profile,version,scenario:'line-subtotal'}).then(order=>expect(order.total).to.equal(shown)));
     setFields({tax_based_on:'item-price'});
   });
 }
});
}
