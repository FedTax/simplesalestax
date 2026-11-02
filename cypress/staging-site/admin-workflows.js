import {reserveOrder} from './workflows';
export function saveOrder(){cy.get('#save-order,button.save_order,#publish').first().click();cy.get('#order_status',{timeout:90000}).should('exist');}
export function recalculate(){cy.on('window:confirm',()=>true);cy.get('button.calculate-action').click();cy.get('#woocommerce-order-items .blockUI',{timeout:90000}).should('not.exist');}
export function openAddress(section,index){cy.get(`#_${section}_first_name`).then($field=>{if(!Cypress.dom.isVisible($field))cy.get('.order_data_column a.edit_address').eq(index).click();});cy.get(`#_${section}_first_name`).should('be.visible');}
export function adminOrder(site,role,{quantity=2,customerId=site.userIds[1],productId=site.products.virtual,initialStatus='wc-pending',addProducts=true}={}){
 cy.qaLogin('admin');reserveOrder('admin',role);cy.visit(site.newOrderUrl);
 if(role!=='guest'){
  cy.intercept('POST','**/admin-ajax.php',req=>{const action=typeof req.body==='string'?new URLSearchParams(req.body).get('action'):req.body?.action;if(action==='woocommerce_get_customer_details')req.alias='loadAdminCustomer';});
  cy.get('#customer_user').parent().find('.select2-selection').click();
  cy.get('.select2-search__field:visible').last().type(String(customerId));
  cy.contains('.select2-results__option',new RegExp(`#${customerId}\\b`),{timeout:60000}).click();
  cy.wait('@loadAdminCustomer',{timeout:90000}).its('response.statusCode').should('equal',200);
  cy.get('#_billing_last_name').should('have.value',Object.values(site.registry.actors).find(x=>x.id===customerId).baseline.last_name);
 }
 openAddress('billing',0);
 cy.then(()=>{
  const name=Cypress.env('qaOrderName');
  for(const [field,value] of Object.entries({first_name:name.firstName,last_name:name.lastName,email:name.email,address_1:'323 Washington Ave N',city:'Minneapolis',postcode:'55401',phone:'6125550100'}))cy.get(`#_billing_${field}`).clear().type(value,{delay:0}).should('have.value',value);
 });
 cy.get('#_billing_country').select('US',{force:true});cy.get('#_billing_state').select('MN',{force:true});
 openAddress('shipping',1);
 cy.then(()=>{const name=Cypress.env('qaOrderName');for(const [field,value] of Object.entries({first_name:name.firstName,last_name:name.lastName,address_1:'323 Washington Ave N',city:'Minneapolis',postcode:'55401'}))cy.get(`#_shipping_${field}`).clear().type(value,{delay:0}).should('have.value',value);});
 cy.get('#_shipping_country').select('US',{force:true});cy.get('#_shipping_state').select('MN',{force:true});
 cy.get('#order_status').select(initialStatus,{force:true});saveOrder();
 if(!addProducts)return cy.location('href').then(url=>{const u=new URL(url);return Number(u.searchParams.get('id')||u.searchParams.get('post'));});
 cy.get('button.add-line-item').click();cy.get('button.add-order-item').click();
 cy.get('body').then($body=>{if(!$body.find('.select2-search__field:visible').length)cy.get('.wc-backbone-modal .select2-selection').first().click();});
 cy.get('.select2-search__field:visible').last().type(String(productId));
 cy.contains('.select2-results__option',new RegExp(`#${productId}\\b`),{timeout:60000}).click();
 cy.get('.wc-backbone-modal input.quantity').first().clear().type(String(quantity));cy.get('.wc-backbone-modal #btn-ok').click();
 cy.get('#order_line_items tr.item',{timeout:90000}).should('have.length',1);
 cy.get('button.add-line-item').click();cy.window().then(win=>cy.stub(win,'prompt').returns('2'));cy.get('button.add-order-fee').click();cy.get('#order_fee_line_items tr.fee',{timeout:90000}).should('have.length',1);
 recalculate();saveOrder();
 return cy.location('href').then(url=>{
  const u=new URL(url);const id=Number(u.searchParams.get('id')||u.searchParams.get('post'));
  expect(id,'admin order ID').to.be.greaterThan(0);return id;
 });
}
