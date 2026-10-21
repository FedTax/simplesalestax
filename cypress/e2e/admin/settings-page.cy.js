/// <reference types="cypress" />

describe('Settings page', () => {
  beforeEach(() => {
    cy.loginAsAdmin();
    cy.goToSettingsPage();
  });

  it('has a heading TaxCloud for WooCommerce', () => {
    cy.findByRole('heading', {name: 'TaxCloud for WooCommerce'}).should('exist');
  });

  it('has a working Verify Settings button', () => {
    cy.intercept('POST', '/wp-admin/admin-ajax.php', (req) => {
      if (req.body.includes('sst_verify_taxcloud')) {
        req.alias = 'verifyRequest';
      }
    });
    cy.on('window:alert', (text) => {
      expect(text).to.eq('Success! Your TaxCloud settings are valid.');
    });
    cy.findByRole('button', {name: 'Verify Settings'}).click();
    cy.wait('@verifyRequest', {timeout: 20000})
      .its('response.body.success').should('equal', true);
  });

  it('has a working Download Log button', () => {
    cy.intercept('*download_debug_report=1*').as('downloadRequest');
    cy.findByRole('link', {name: 'Download'}).click();
    cy.wait('@downloadRequest', {timeout: 20000}).then((intercepted) => {
      expect(intercepted.response.statusCode).to.eq(200);
      expect(intercepted.response.headers['content-disposition']).to.match(/filename=sst_debug_report_(.*).txt$/);
    });
  });

  it('saves and restores the selected TaxCloud API version', () => {
    const saveSettings = () => {
      cy.findByRole('button', {name: 'Save changes'}).click();
      cy.findByText(/saved/i, {timeout: 15000}).should('exist');
    };

    // Select2 hides the native select from the accessibility tree.
    const apiVersion = () => cy.get('#woocommerce_wootax_api_version');
    apiVersion()
      .invoke('val')
      .then((originalVersion) => {
        const changedVersion = originalVersion === 'v3' ? 'v1' : 'v3';
        apiVersion().select(changedVersion, {force: true});
        saveSettings();
        cy.reload();
        apiVersion().should('have.value', changedVersion);

        apiVersion().select(originalVersion, {force: true});
        saveSettings();
        cy.reload();
        apiVersion().should('have.value', originalVersion);
      });
  });

  describe('when block cart/checkout is enabled', () => {
    before(() => {
      cy.loginAsAdmin();
      cy.useClassicCart(false);
    });

    it('should disable Show Zero Tax dropdown', () => {
      cy.findByRole('combobox', {name: /Show Zero Tax On Cart Page/i }).should('be.disabled');
    });
  });
});
