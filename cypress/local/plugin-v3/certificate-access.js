// DEV-10622: real WordPress authorization and WooCommerce billing-address edits.
// Only the TaxCloud HTTP boundary is simulated by the existing fixture runner.
module.exports = ({ task, records, login }) => {
  describe('DEV-10622 certificate access (local fixtures only)', () => {
    let fixture;
    let recordOffset;
    const certificateFields = {
      'certificate[ExemptState]': 'MN', 'certificate[TaxType]': 'FEIN',
      'certificate[IDNumber]': 'QA-ACCESS', 'certificate[StateOfIssue]': '',
      'certificate[PurchaserBusinessType]': 'RetailTrade', 'certificate[PurchaserBusinessTypeOtherValue]': '',
      'certificate[PurchaserExemptionReason]': 'Resale', 'certificate[PurchaserExemptionReasonOtherValue]': 'Resale exemption',
      'address[first_name]': 'Cypress', 'address[last_name]': 'Access',
      'address[address_1]': '323 Washington Ave N', 'address[address_2]': '',
      'address[city]': 'Minneapolis', 'address[state]': 'MN', 'address[postcode]': '55401',
    };
    const request = (action, nonce, fields = {}) => cy.request({
      method: 'POST', url: `/wp-admin/admin-ajax.php?action=sst_${action}`, form: true,
      failOnStatusCode: false, body: { nonce, ...fields },
    });
    const visitOwnCertificates = () => {
      login();
      cy.visit('/my-account/exemption-certificates/');
      cy.then(() => {
        cy.get(`#sst-certificates tr[data-id="${fixture.ownCertificateId}"]`).should('exist');
        cy.get(`#sst-certificates tr[data-id="${fixture.foreignCertificateId}"]`).should('not.exist');
      });
    };

    beforeEach(() => task('certificateAccess').then((data) => { fixture = data; }));
    afterEach(() => task('certificateAccessReset'));

    it('rejects cross-account add, read, refresh and delete with valid customer nonces', () => {
      visitOwnCertificates();
      records().then((rows) => { recordOffset = rows.length; });
      cy.window().then((win) => {
        const table = win.SST_Certificate_Table_Data;
        [
          ['add_certificate', win.SST_Add_Certificate_Data.nonce, certificateFields],
          ['get_certificates', table.get_certificates_nonce, {}],
          ['refresh_certificates', table.refresh_certificates_nonce, {}],
          ['delete_certificate', table.delete_certificate_nonce, { certificate_id: fixture.foreignCertificateId }],
        ].forEach(([action, nonce, fields]) => {
          request(action, nonce, { ...fields, user_id: fixture.foreignUserId }).then(({ status, body }) => {
            expect(status, action).to.equal(403);
            expect(body).to.deep.equal({ success: false, data: 'Unauthorized.' });
          });
        });
      });
      records().then((rows) => expect(rows.slice(recordOffset), 'rejected requests make no TaxCloud calls').to.be.empty);
    });

    it('ignores a foreign billing email and any certificate cache left by the old plugin', () => {
      visitOwnCertificates();
      cy.visit('/my-account/edit-address/billing/');
      cy.then(() => cy.get('#billing_email').clear().type(fixture.foreignEmail));
      cy.get('button[name="save_address"]').click();
      cy.get('.woocommerce-message').should('contain.text', 'Address changed successfully');
      records().then((rows) => { recordOffset = rows.length; });
      cy.visit('/my-account/exemption-certificates/');
      cy.get('.sst-certificate-refresh').click();
      cy.get('.blockUI').should('not.exist');
      cy.window().then((win) => request('get_certificates', win.SST_Certificate_Table_Data.get_certificates_nonce)
        .then(({ status, body }) => {
          expect(status).to.equal(200);
          expect(body.success).to.equal(true);
          expect(body.data).to.have.property(fixture.ownCertificateId);
          expect(body.data).not.to.have.property(fixture.foreignCertificateId);
        }));
      records().then((rows) => {
        const lists = rows.slice(recordOffset).filter((row) => row.method === 'GET' && row.path === '/tax/exemption-certificates');
        expect(lists, 'refresh performs account-identity lookups').not.to.be.empty;
        lists.forEach((row) => row.response.items.forEach((certificate) =>
          expect(certificate.certificateId, 'lookup never returns the foreign email-linked certificate').not.to.equal(fixture.foreignCertificateId)));
      });
    });

    it('allows admins and shop managers to create and read permitted customer certificates', () => {
      ['admin', 'manager'].forEach((role) => {
        if (role === 'admin') {
          login('admin');
        } else {
          cy.clearAllCookies();
          cy.setCookie('sst_e2e_run', Cypress.env('pluginV3Run'));
          cy.visit('/wp-login.php');
          cy.then(() => cy.request({ method: 'POST', url: '/wp-login.php', form: true, log: false,
            body: { log: fixture.managerUsername, pwd: fixture.managerPassword, 'wp-submit': 'Log In', testcookie: '1' } }));
        }
        cy.then(() => cy.visit(`/wp-admin/user-edit.php?user_id=${fixture.foreignUserId}`));
        cy.then(() => cy.get(`#sst-certificates tr[data-id="${fixture.foreignCertificateId}"]`).should('exist'));
        cy.window().then((win) => {
          request('add_certificate', win.SST_Add_Certificate_Data.nonce, { ...certificateFields, user_id: fixture.foreignUserId })
            .then(({ status, body }) => {
              expect(status).to.equal(200);
              expect(body.success, JSON.stringify(body)).to.equal(true);
              expect(body.data.certificates).to.have.property(body.data.certificate_id);
            });
          if (role === 'manager') {
            request('get_certificates', win.SST_Certificate_Table_Data.get_certificates_nonce, { customerId: fixture.adminId })
              .then(({ status, body }) => {
                expect(status, 'shop managers cannot access administrators').to.equal(403);
                expect(body).to.deep.equal({ success: false, data: 'Unauthorized.' });
              });
          }
        });
      });
      records().then((rows) => {
        const creates = rows.filter((row) => row.method === 'POST' && /\/exemption-certificates$/.test(row.path)
          && row.request.customerId === String(fixture.foreignUserId));
        expect(creates, 'both staff requests target the selected customer').to.have.length(2);
      });
    });
  });
};
