// Run separately on the original active-plugin configuration, after explicitly restoring PayPal activation through WP admin in a prepared run.
// It is intentionally excluded from the functional run by the suite selector below.
describe('Original plugin configuration: TaxCloud settings compatibility',()=>{
 it('loads TaxCloud settings without an uncaught PayPal/React error',()=>{
   cy.qaLogin('admin');cy.visit('wp-admin/admin.php?page=wc-settings&tab=integration&section=wootax');
   cy.get('#woocommerce_wootax_api_version').should('be.visible');
 });
});
