import {task,configure,settingsPath} from '../workflows';
describe('Admin surfaces on the deployed TaxCloud plugin',()=>{
 let site;
 before(()=>{cy.task('qaSite',null,{log:false}).then(x=>{site=x;});configure('realtime','v3');});
 it('opens TaxCloud settings through the installed plugin Settings link',()=>{
   cy.qaLogin('admin');cy.visit('wp-admin/plugins.php');
   cy.get('tr[data-plugin="simple-sales-tax/simple-sales-tax.php"]').contains('a','Settings').click();
   cy.location('search').should('include','section=wootax');cy.get('#verifySettings').should('be.visible');
 });
 it('downloads a debug report with a successful response and expected filename',()=>{
   cy.qaLogin('admin');cy.visit(settingsPath);
   // A browser attachment can leave Cypress waiting for a navigation that never loads a document.
   // Exercise the real authenticated download URL exposed by the settings control; keep report contents out of logs.
   cy.get('a[href*="download_debug_report=1"]').invoke('attr','href').then(url=>cy.request({url,log:false}).then(response=>{
     expect(response.status).to.equal(200);expect(response.headers['content-disposition']).to.match(/filename=sst_debug_report_.*\.txt/);
   }));
 });
 it('disables classic zero-tax display settings while Blocks pages are assigned',()=>{
   cy.qaLogin('admin');task('layout',{layout:'blocks'});cy.visit(settingsPath);cy.get('#woocommerce_wootax_show_zero_tax').should('be.disabled');
   task('layout',{layout:'classic'});cy.reload();cy.get('#woocommerce_wootax_show_zero_tax').should('not.be.disabled');
 });
 it('bulk-edits the run-owned product TICs and verifies each saved value',()=>{
   cy.qaLogin('admin');cy.visit('wp-admin/edit.php?post_type=product');
   for(const id of [site.products.virtual,site.products.physical])cy.get(`input[name="post[]"][value="${id}"]`).check({force:true});
   cy.get('#bulk-action-selector-top').select('edit');cy.get('#doaction').click();
   cy.get('#bulk-edit .sst-select-tic').first().click();cy.get('.sst-tic-search').clear().type('Uncategorized');
   cy.contains('.tic-row','Uncategorized',{timeout:60000}).find('button').click();cy.get('#bulk-edit #bulk_edit').click();
   cy.contains('.notice','2 products updated').should('exist');
   for(const id of [site.products.virtual,site.products.physical]){cy.visit(`wp-admin/post.php?post=${id}&action=edit`);cy.get('.sst-tic-input').first().should('have.value','00000');}
 });
 it('creates and reloads a retained category with its selected TIC',()=>{
   cy.qaLogin('admin');cy.visit('wp-admin/edit-tags.php?taxonomy=product_cat&post_type=product');
   const name=`SST QA ${Cypress.env('qaRunId').slice(0,8)} category`;
   cy.get('#tag-name').type(name);cy.get('#addtag .sst-select-tic').first().click();cy.get('.sst-tic-search').clear().type('Uncategorized');
   cy.contains('.tic-row','Uncategorized',{timeout:60000}).find('button').click();cy.get('#submit').click();
   cy.contains('#the-list .row-title',name).click();cy.get('.sst-tic-input').first().should('have.value','00000');
   cy.location('href').then(url=>cy.task('qaResult',{id:'retained-category',name,editUrl:url},{log:false}));
 });
});
