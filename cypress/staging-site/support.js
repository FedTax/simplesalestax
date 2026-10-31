Cypress.Screenshot.defaults({blackout:['#woocommerce_wootax_tc_id','#woocommerce_wootax_tc_key','input[type="password"]','input[name="log"]'],capture:'viewport'});
// Preserve all app errors. This exact cross-document transition error is reproduced
// in regular Chrome on the WordPress staging home/cart; record it and continue the
// functional check so it does not mask checkout/plugin results. Strict frontend
// specs opt out of that allowance and fail on this exception too.
Cypress.on('uncaught:exception',err=>{
  if(String(err.message).includes('Transition was aborted because of invalid state. ViewTransition opt-in disabled')){
    Cypress.env('qaWordPressViewTransitionErrors',Number(Cypress.env('qaWordPressViewTransitionErrors')||0)+1);
    if(!Cypress.env('qaStrictFrontend'))return false;
  }
  return true;
});
beforeEach(()=>Cypress.env('qaWordPressViewTransitionErrors',0));
afterEach(function(){
  cy.task('qaResult',{title:this.currentTest.fullTitle(),state:this.currentTest.state,error:this.currentTest.err?.message||null},{log:false});
  const count=Number(Cypress.env('qaWordPressViewTransitionErrors')||0);
  if(count)cy.task('qaResult',{id:'wordpress-view-transition-error',count,source:'confirmed in regular Chrome; exact exception is recorded; strict frontend specs fail, other checkout specs continue'},{log:false});
});
Cypress.Commands.add('qaLogin',(role='buyer')=>{
  cy.session([Cypress.env('qaRunId'),role],()=>{
    cy.task('qaLogin',{role},{log:false}).then(person=>{
      cy.visit('wp-login.php');
      cy.request({method:'POST',url:'wp-login.php',form:true,log:false,body:{log:person.username,pwd:person.password,testcookie:'1','wp-submit':'Log In'}});
      cy.getAllCookies({log:false}).should(cookies=>expect(cookies.some(x=>x.name.startsWith('wordpress_logged_in_'))).to.equal(true));
    });
  });
  cy.setCookie('sst_qa_run',Cypress.env('qaRunId'),{log:false});
});
