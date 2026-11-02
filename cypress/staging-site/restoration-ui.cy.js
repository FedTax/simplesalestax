import {settingsPath,saveSettings,selectFirstOrigin} from './workflows';
describe('Original credential and origin restoration UI',()=>{
 it('saves original credentials, explicitly selects the first valid origin and restores the original selection',()=>{
  cy.qaLogin('admin');cy.visit(settingsPath);
  cy.task('qaRestoration',null,{log:false}).then(original=>{
   expect(Boolean(original.login)&&Boolean(original.key),'original connection credentials available').to.equal(true);
   cy.get('#woocommerce_wootax_tc_id',{log:false}).clear({log:false}).type(original.login,{log:false,parseSpecialCharSequences:false});
   cy.get('#woocommerce_wootax_tc_key',{log:false}).clear({log:false}).type(original.key,{log:false,parseSpecialCharSequences:false});
   cy.get('#woocommerce_wootax_api_version').select(original.apiVersion,{force:true});
   saveSettings();cy.reload();selectFirstOrigin();
   cy.get('#woocommerce_wootax_api_version').select(original.apiVersion,{force:true});
   cy.get('#woocommerce_wootax_default_origin_addresses').select([].concat(original.origins||[]).map(String),{force:true});saveSettings();cy.reload();
   cy.get('#woocommerce_wootax_tc_id',{log:false}).should($el=>expect($el.val()===original.login,'original login restored').to.equal(true));
   cy.get('#woocommerce_wootax_tc_key',{log:false}).should($el=>expect($el.val()===original.key,'original key restored').to.equal(true));
   cy.get('#woocommerce_wootax_default_origin_addresses').should($el=>expect([].concat($el.val()||[]).map(String).sort(),'exact original origins').to.deep.equal([].concat(original.origins||[]).map(String).sort()));
  });
 });
});
