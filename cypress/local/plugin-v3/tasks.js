const { execFile } = require('node:child_process');
const { promisify } = require('node:util');
const { randomUUID } = require('node:crypto');
const path = require('node:path');
const fs = require('node:fs/promises');
const exec = promisify(execFile);

module.exports = (on, config) => {
  const mode = config.env.pluginV3Mode || 'live';
  if (!['live', 'fixture'].includes(mode)) throw new Error('pluginV3Mode must be live or fixture.');
  if (mode === 'live' && config.env.taxcloudTestModeConfirmed !== true && config.env.taxcloudTestModeConfirmed !== 'true') {
    throw new Error('Confirm the saved TaxCloud connection is in Test mode, then pass --env taxcloudTestModeConfirmed=true. Live tests create orders, certificates and refunds.');
  }
  const container = config.env.apiV3WordpressContainer || 'docker-wordpress-1';
  let run;
  let directory;
  const helper = '/var/www/html/wp-content/mu-plugins/sst-cypress-v3.php';
  let installed = false;
  let prepared = false;
  const docker = (...args) => exec('docker', args, { timeout: 90000, maxBuffer: 4 * 1024 * 1024 });
  const control = async (action, data = {}) => {
    const payload = Buffer.from(JSON.stringify({ action, run, mode, ...data })).toString('base64');
    const { stdout } = await docker('exec', '--user', 'www-data', '--env', `SST_E2E_RUN=${run}`, '--env', `SST_E2E_PAYLOAD=${payload}`,
      container, 'wp', 'eval-file', `${directory}/control.php`, '--allow-root');
    const marker = stdout.lastIndexOf('SST_E2E_RESULT:');
    if (marker < 0) throw new Error(`WordPress test task failed: ${stdout}`);
    return JSON.parse(stdout.slice(marker + 'SST_E2E_RESULT:'.length));
  };
  const cleanup = async () => {
    if (!installed && !prepared) return;
    try {
      if (prepared) {
        const records = await control('records');
        const evidenceDirectory = path.join(config.projectRoot, 'cypress/results/plugin-v3');
        await fs.mkdir(evidenceDirectory, { recursive: true });
        const evidence = JSON.stringify({ run, mode, records }, null, 2);
        await fs.writeFile(path.join(evidenceDirectory, 'latest.json'), evidence);
        await fs.writeFile(path.join(evidenceDirectory, `latest-${mode}.json`), evidence);
        await control('cleanup');
      }
    }
    finally {
      if (installed) await docker('exec', container, 'rm', '-f', helper);
      await docker('exec', container, 'rm', '-rf', directory);
      installed = false;
      prepared = false;
    }
  };
  const prepare = async () => {
    run = randomUUID();
    directory = `/tmp/sst-cypress-${run}`;
    const probe = await docker('exec', container, 'test', '!', '-e', helper);
    void probe;
    await exec('rsync', ['-a', '--exclude-from=.distignore', config.projectRoot + '/', path.join(config.projectRoot, 'docker/plugin/')], { timeout: 60000 });
    await docker('exec', container, 'mkdir', '-p', directory, '/var/www/html/wp-content/mu-plugins');
    await docker('cp', path.join(config.projectRoot, 'cypress/local/plugin-v3/server.php'), `${container}:${helper}`);
    installed = true;
    await docker('cp', path.join(config.projectRoot, 'cypress/local/plugin-v3/control.php'), `${container}:${directory}/control.php`);
    await docker('exec', container, 'chown', 'www-data:www-data', directory);
    try {
      prepared = true;
      await control('setup');
    } catch (error) {
      await cleanup();
      throw error;
    }
  };
  if (!config.isInteractive) on('before:run', prepare);
  on('after:run', cleanup);
  on('task', { pluginV3: async ({ action, ...data }) => {
    if (action === 'begin') {
      if (config.isInteractive) {
        await cleanup();
        await prepare();
      }
      return { ...await control('info'), run };
    }
    if (action === 'end') {
      if (config.isInteractive) await cleanup();
      return true;
    }
    return control(action, data);
  } });
  config.env.pluginV3Mode = mode;
  return config;
};
