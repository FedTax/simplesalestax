module.exports = {
  e2e: {
    // Local V3 API suites have separate configs and must stay out of CI.
    specPattern: 'cypress/e2e/**/*.cy.{js,jsx,ts,tsx}',
    // We've imported your old cypress plugins here.
    // You may want to clean this up later by importing these.
    setupNodeEvents(on, config) {
      return require('./cypress/plugins/index.js')(on, config)
    },
    baseUrl: 'http://localhost:8080',
    env: {
      REST_API_KEY: 'ck_bf5e687a10f702624e4dc556869f5160b969f2b3',
      REST_API_SECRET: 'cs_e02b6d28c2c3b0db2cae06c21e210a878aad3c3b',
    },
  },
  retries: 2,
  pageLoadTimeout: 120000,
}
