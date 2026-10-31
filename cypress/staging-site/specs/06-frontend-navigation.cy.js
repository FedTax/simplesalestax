// Keep known frontend exceptions visible as failures in this dedicated gate.
describe('strict storefront navigation',()=>{
  before(()=>{Cypress.env('qaStrictFrontend',true);});
  after(()=>{Cypress.env('qaStrictFrontend',false);});
  for(const role of ['guest','buyer'])it(`${role} navigates home, cart and account without uncaught exceptions`,()=>{
    if(role==='guest')cy.clearAllCookies({log:false});else cy.qaLogin('buyer');
    cy.visit(Cypress.config('baseUrl'));
    cy.contains('a',/^Cart$/).first().then($link=>{
      const destination=new URL($link.attr('href'),Cypress.config('baseUrl')).href;
      cy.wrap($link).click();cy.url().should('equal',destination);
    });
    cy.get('body').should('contain.text','Cart');
    cy.go('back');cy.location('pathname').should('equal',new URL(Cypress.config('baseUrl')).pathname);
    cy.contains('a',/^My account$/).first().click();
    cy.get('body').should('contain.text',role==='guest'?'Login':'Dashboard');
    cy.then(()=>expect(Cypress.env('qaWordPressViewTransitionErrors'),'navigation transition errors').to.equal(0));
  });
});
