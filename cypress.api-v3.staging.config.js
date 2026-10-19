const { defineConfig } = require('cypress');

module.exports = defineConfig({
  video: false,
  retries: 0, // A failed mutation must never be retried automatically.
  taskTimeout: 90000,
  e2e: {
    specPattern: 'cypress/local/api-v3/live.cy.js',
    supportFile: false,
    setupNodeEvents(on, config) {
      if (process.env.GITHUB_ACTIONS === 'true' || (process.env.CI && process.env.CI !== 'false')) {
        throw new Error('Live TaxCloud V3 staging tests are local only.');
      }
      return require('./cypress/local/api-v3/live-tasks')(on, config);
    },
  },
});
