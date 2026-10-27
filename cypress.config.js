module.exports = {
  e2e: {
    // Default suite: admin only, including GitHub Actions.
    specPattern: 'cypress/e2e/**/*.cy.{js,jsx,ts,tsx}',
    setupNodeEvents(on, config) {
      return require('./cypress/suites')(on, config)
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
