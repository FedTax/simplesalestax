/// <reference types="cypress" />

describe('Bulk editor', () => {
  beforeEach(() => {
    cy.loginAsAdmin();
    cy.visit('/wp-admin/edit.php?post_type=product');
  });

  const selectProduct = (productName) => {
    cy.findByRole('link', {name: productName})
      .closest('tr')
      .find('input[type="checkbox"]')
      .check({force: true});
  };

  it('allows user to bulk edit TICs', () => {
    const products = ['General Product', 'Lumber'];

    products.forEach((name) => {
      selectProduct(name);
    });

    // Would prefer to use findByRole here but had issues with that.
    cy.get('#bulk-action-selector-top').select('edit');
    cy.get('#doaction').click();
    cy.get('#bulk-edit'); // Wait for editor to be visible.

    cy.contains('label', 'TIC').find('.sst-select-tic').click();
    cy.get('.sst-tic-search').type('uncat');
    cy.contains('.tic-row', 'Uncategorized')
      .find('button')
      .click();

    // Assert selected TIC is displayed.
    cy.contains('label', 'TIC')
      .contains('span', 'Uncategorized')
      .should('be.visible');

    // Submit bulk edit form.
    cy.findByRole('button', {name: 'Update'}).click();
    cy.contains('.notice', '2 products updated.').should('be.visible');

    // Verify correct TIC was saved.
    products.forEach((productName) => {
      cy.findByRole('link', {name: productName}).click();
      cy.contains('.sst-selected-tic', 'Uncategorized').should('exist');
      cy.go('back');
    });
  });
});
