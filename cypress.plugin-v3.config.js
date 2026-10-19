const { defineConfig } = require('cypress');

module.exports = defineConfig({
  video: false,
  retries: 0,
  experimentalInteractiveRunEvents: true,
  defaultCommandTimeout: 20000,
  requestTimeout: 60000,
  responseTimeout: 60000,
  taskTimeout: 90000,
  e2e: {
    baseUrl: 'http://localhost:8080',
    specPattern: 'cypress/local/plugin-v3/**/*.cy.js',
    supportFile: false,
    setupNodeEvents(on, config) {
      if (process.env.GITHUB_ACTIONS === 'true' || (process.env.CI && process.env.CI !== 'false')) {
        throw new Error('WooCommerce V3 E2E tests are local only.');
      }
      if (new URL(config.baseUrl).hostname !== 'localhost') {
        throw new Error('This suite requires the local Docker WordPress site.');
      }
      return require('./cypress/local/plugin-v3/tasks')(on, config);
    },
  },
});
