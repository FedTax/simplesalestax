/// <reference types="cypress" />

describe('Settings page', () => {
  beforeEach(() => {
    cy.loginAsAdmin();
    cy.goToSettingsPage();
  });

  it('has a heading TaxCloud Settings', () => {
    cy.findByText('TaxCloud Settings').should('exist');
  });

  it('has a working Verify Settings button', () => {
    cy.intercept('POST', '/wp-admin/admin-ajax.php', (req) => {
      if (req.body.includes('sst_verify_taxcloud')) {
        req.alias = 'verifyRequest';
      }
    });
    cy.get('#verifySettings').click();
    cy.wait('@verifyRequest', {timeout: 20000});
    cy.on('window:alert', (text) => {
      expect(text).to.eq('Success! Your TaxCloud settings are valid.');
    });
  });

  it('has a working Download Log button', () => {
    cy.intercept('*&download_debug_report=1').as('downloadRequest');
    cy.get('#debug_report_button').click();
    cy.wait('@downloadRequest', {timeout: 20000}).then((intercepted) => {
      expect(intercepted.response.statusCode).to.eq(200);
      expect(intercepted.response.headers['content-disposition']).to.match(/filename=sst_debug_report_(.*).txt$/);
    });
  });

  describe('when block cart/checkout is enabled', () => {
    before(() => {
      cy.loginAsAdmin();
      cy.useClassicCart(false);
    });

    it('should disable Show Zero Tax dropdown', () => {
      cy.findByRole('combobox', {name: /Show Zero Tax/i }).should('be.disabled');
    });
  });
});
