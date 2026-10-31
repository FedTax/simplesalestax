// Safe preflight subset: no settings saves, cart operations, order placement or TaxCloud mutations.
describe('Non-transactional staging checks',()=>{
 let site;
 before(()=>cy.task('qaSite',null,{log:false}).then(x=>{site=x;}));
 it('loads the TaxCloud settings without uncaught frontend errors',()=>{
   cy.qaLogin('admin');cy.visit('wp-admin/admin.php?page=wc-settings&tab=integration&section=wootax');
   cy.contains('TaxCloud Settings').should('be.visible');cy.get('#woocommerce_wootax_api_version').should($el=>expect(['v1','v3']).to.include($el.val()));
 });
 it('provides selectable real origin addresses',()=>{
   cy.qaLogin('admin');cy.visit('wp-admin/admin.php?page=wc-settings&tab=integration&section=wootax');
   cy.get('#woocommerce_wootax_default_origin_addresses option').should($options=>expect([...$options].some(x=>x.value&&!x.disabled),'available origin address').to.equal(true));
 });
 it('renders the tagged variable product with variation choices',()=>{
   cy.visit(`?p=${site.products.variable}`);cy.get('select[name="attribute_size"]').should('exist').find('option').should('have.length.at.least',3);
 });
 it('allows the generated customer to reach My Account',()=>{
   cy.qaLogin('buyer');cy.visit(site.pages.myAccount);cy.get('.woocommerce-MyAccount-navigation').should('be.visible');
 });
});
