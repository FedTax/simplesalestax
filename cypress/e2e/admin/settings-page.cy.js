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
    cy.findByRole('link', {name: 'Download'}).should('be.visible').invoke('attr', 'href').then((href) => {
      cy.request(href).then((response) => {
        expect(response.status).to.eq(200);
        expect(response.headers['content-disposition']).to.match(/filename=sst_debug_report_(.*).txt$/);
      });
    });
  });

  it('saves and restores the selected TaxCloud API version', () => {
    const saveSettings = () => {
      cy.findByRole('button', {name: 'Save changes'}).click();
      cy.findByText('Your settings have been saved.', {timeout: 15000}).should('exist');
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

  it('relabels the existing connection field and previews native V3 origins', () => {
    const version = '#woocommerce_wootax_api_version';
    const connection = '#woocommerce_wootax_tc_key';
    const apiKey = '#woocommerce_wootax_tc_v3_api_key';
    cy.get(version).select('v1', {force: true});
    cy.get('#woocommerce_wootax_tc_id').should('be.visible');
    cy.get(apiKey).should('not.be.visible');

    cy.get(connection).invoke('val').then((previousConnection) => {
      cy.get(version).select('v3', {force: true});
      cy.get(connection).should('have.value', previousConnection);
    });
    cy.get('label[for="woocommerce_wootax_tc_key"]').find('.sst-v3-connection').should('be.visible');
    cy.get('#woocommerce_wootax_tc_id').should('not.be.visible');
    cy.get(apiKey).should('be.visible').and('have.attr', 'type', 'password');
    cy.get(apiKey).should('have.value', '').type('browser-fixture-key', {log: false});

    cy.intercept('POST', '/wp-admin/admin-ajax.php', (req) => {
      const data = new URLSearchParams(req.body);
      if (data.get('action') !== 'sst_verify_taxcloud') return;
      expect(data.get('wootax_api_version')).to.equal('v3');
      expect(data.has('wootax_tc_id')).to.equal(false);
      req.alias = 'verifyV3';
      req.reply({success: true, data: {origins: [
        {id: 'new-origin-1', label: 'First TaxCloud origin'},
        {id: 'new-origin-2', label: 'Second TaxCloud origin'},
      ]}});
    });
    cy.findByRole('button', {name: 'Verify Settings'}).click();
    cy.wait('@verifyV3');
    cy.get('#sst-origin-options').should('be.visible');
    cy.get('#woocommerce_wootax_default_origin_addresses').should(($origins) => {
      expect($origins.val()).to.deep.equal([]);
      expect($origins.find('option')).to.have.length(2);
    });

    cy.get(version).select('v1', {force: true});
    cy.get(apiKey).should('not.be.visible');
    cy.get('#woocommerce_wootax_default_origin_addresses option[value="new-origin-1"]').should('not.exist');
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
