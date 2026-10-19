const { defineConfig } = require('cypress');

module.exports = defineConfig({
  video: false,
  retries: 0,
  taskTimeout: 60000,
  e2e: {
    specPattern: 'cypress/local/api-v3/contracts.cy.js',
    supportFile: false,
    setupNodeEvents(on, config) {
      if (process.env.GITHUB_ACTIONS === 'true' || (process.env.CI && process.env.CI !== 'false')) {
        throw new Error('TaxCloud V3 API contract tests are local only. Run npm run cypress:api:v3 locally.');
      }
      return require('./cypress/local/api-v3/tasks')(on, config);
    },
  },
});
