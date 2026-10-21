// One Cypress config selects one suite. Only admin tests are allowed in CI.
module.exports = (on, config) => {
  const suite = config.env.suite || 'admin';
  if (suite === 'admin') return config;
  if (!['plugin', 'contracts', 'staging'].includes(suite)) {
    throw new Error('Unknown Cypress suite. Choose admin, plugin, contracts or staging.');
  }
  if (process.env.GITHUB_ACTIONS === 'true' || (process.env.CI && process.env.CI !== 'false')) {
    throw new Error(`The ${suite} Cypress suite is local only.`);
  }

  Object.assign(config, {video: false, retries: 0, supportFile: false, taskTimeout: 90000});
  if (suite === 'plugin') {
    if (new URL(config.baseUrl).hostname !== 'localhost') {
      throw new Error('Plugin E2E tests require the local Docker WordPress site.');
    }
    Object.assign(config, {
      specPattern: 'cypress/local/plugin-v3/**/*.cy.js',
      experimentalInteractiveRunEvents: true,
      defaultCommandTimeout: 20000,
      requestTimeout: 60000,
      responseTimeout: 60000,
    });
    config.env.pluginV3Mode = config.env.mode || 'fixture';
    return require('./local/plugin-v3/tasks')(on, config);
  }
  config.baseUrl = null; // Isolated API suites do not require a running website.
  config.specPattern = `cypress/local/api-v3/${suite === 'contracts' ? 'contracts' : 'live'}.cy.js`;
  return require(suite === 'contracts' ? './local/api-v3/tasks' : './local/api-v3/live-tasks')(on, config);
};
