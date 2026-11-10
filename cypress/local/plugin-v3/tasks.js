const { execFile } = require('node:child_process');
const { promisify } = require('node:util');
const { randomUUID } = require('node:crypto');
const path = require('node:path');
const fs = require('node:fs/promises');
const { loadCredentials } = require('./credentials');
const exec = promisify(execFile);

module.exports = (on, config) => {
  const mode = config.env.pluginV3Mode || 'live';
  if (!['live', 'fixture'].includes(mode)) throw new Error('pluginV3Mode must be live or fixture.');
  if (mode === 'live' && config.env.taxcloudTestModeConfirmed !== true && config.env.taxcloudTestModeConfirmed !== 'true') {
    throw new Error('Confirm both configured connections are in Test mode, then run npm run test:e2e -- --live. Live tests create orders, certificates and refunds.');
  }
  const container = config.env.apiV3WordpressContainer || 'docker-wordpress-1';
  // Opt in explicitly to a LocalWP checkout; Docker remains the default.
  const localPath = process.env.SST_E2E_WORDPRESS_PATH && path.resolve(process.env.SST_E2E_WORDPRESS_PATH);
  const phpArgs = process.env.SST_E2E_MYSQL_SOCKET ? ['-d', `mysqli.default_socket=${process.env.SST_E2E_MYSQL_SOCKET}`] : [];
  let wpCli;
  let run;
  let directory;
  const helper = localPath ? path.join(localPath, 'wp-content/mu-plugins/sst-cypress-v3.php') : '/var/www/html/wp-content/mu-plugins/sst-cypress-v3.php';
  let installed = false;
  let prepared = false;
  let credentials;
  const redact = (message) => Object.values(credentials || {}).reduce((text, secret) => text.split(secret).join('[redacted]'), message);
  const docker = async (...args) => {
    try {
      return await exec('docker', args, { timeout: 90000, maxBuffer: 4 * 1024 * 1024 });
    } catch (error) {
      throw new Error(redact(error.message));
    }
  };
  const writeCredentials = () => new Promise((resolve, reject) => {
    // Send secrets over stdin, not command arguments/Cypress env. Only www-data
    // can read this temporary file, which cleanup removes with the run directory.
    const child = execFile('docker', ['exec', '-i', '--user', 'www-data', container,
      'sh', '-c', 'umask 077; cat > "$1"', 'sh', `${directory}/credentials.json`],
    { timeout: 30000 }, (error) => error ? reject(new Error(redact(error.message))) : resolve());
    child.stdin.on('error', () => {}); // The exit callback reports write failures.
    child.stdin.end(JSON.stringify(credentials));
  });
  const control = async (action, data = {}) => {
    const payload = Buffer.from(JSON.stringify({ action, run, mode, ...data })).toString('base64');
    const { stdout } = localPath
      ? await exec(process.env.SST_E2E_PHP_BINARY || 'php', [...phpArgs, wpCli, `--path=${localPath}`, 'eval-file', `${directory}/control.php`],
        { timeout: 90000, maxBuffer: 4 * 1024 * 1024, env: { ...process.env, SST_E2E_RUN: run, SST_E2E_PAYLOAD: payload } })
      : await docker('exec', '--user', 'www-data', '--env', `SST_E2E_RUN=${run}`, '--env', `SST_E2E_PAYLOAD=${payload}`,
        container, 'wp', 'eval-file', `${directory}/control.php`, '--allow-root');
    const marker = stdout.lastIndexOf('SST_E2E_RESULT:');
    if (marker < 0) throw new Error(redact(`WordPress test task failed: ${stdout}`));
    return JSON.parse(redact(stdout.slice(marker + 'SST_E2E_RESULT:'.length)));
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
      if (localPath) {
        if (installed) await fs.rm(helper, { force: true });
        await fs.rm(directory, { recursive: true, force: true });
      } else {
        if (installed) await docker('exec', container, 'rm', '-f', helper);
        await docker('exec', container, 'rm', '-rf', directory);
      }
      installed = false;
      prepared = false;
    }
  };
  const prepare = async () => {
    credentials = mode === 'live' ? await loadCredentials(config.projectRoot, config.env.pluginV3CredentialsFile) : null;
    run = randomUUID();
    directory = `/tmp/sst-cypress-${run}`;
    if (localPath) {
      wpCli = process.env.SST_E2E_WP_CLI || (await exec('which', ['wp'])).stdout.trim();
      const plugin = path.join(localPath, 'wp-content/plugins/simple-sales-tax');
      if (await fs.realpath(plugin) !== await fs.realpath(config.projectRoot)) {
        throw new Error('LocalWP must load this exact plugin checkout.');
      }
      // Verify the requested site before installing the temporary helper.
      const { stdout } = await exec(process.env.SST_E2E_PHP_BINARY || 'php', [...phpArgs, wpCli, `--path=${localPath}`, 'option', 'get', 'home']);
      if (stdout.trim().replace(/\/$/, '') !== config.baseUrl.replace(/\/$/, '')) {
        throw new Error('Cypress baseUrl must match the LocalWP home URL.');
      }
      await fs.mkdir(path.dirname(helper), { recursive: true });
      await fs.copyFile(path.join(config.projectRoot, 'cypress/local/plugin-v3/server.php'), helper, fs.constants.COPYFILE_EXCL);
      installed = true;
      try {
        await fs.mkdir(directory, { mode: 0o700 });
        await fs.copyFile(path.join(config.projectRoot, 'cypress/local/plugin-v3/control.php'), `${directory}/control.php`);
        if (credentials) await fs.writeFile(`${directory}/credentials.json`, JSON.stringify(credentials), { mode: 0o600 });
        prepared = true;
        await control('setup', { localwp: true });
      } catch (error) {
        await cleanup();
        throw error;
      }
      return;
    }
    const probe = await docker('exec', container, 'test', '!', '-e', helper);
    void probe;
    await exec('rsync', ['-a', '--exclude-from=.distignore', config.projectRoot + '/', path.join(config.projectRoot, 'docker/plugin/')], { timeout: 60000 });
    await docker('exec', container, 'mkdir', '-p', directory, '/var/www/html/wp-content/mu-plugins');
    await docker('cp', path.join(config.projectRoot, 'cypress/local/plugin-v3/server.php'), `${container}:${helper}`);
    installed = true;
    await docker('cp', path.join(config.projectRoot, 'cypress/local/plugin-v3/control.php'), `${container}:${directory}/control.php`);
    await docker('exec', container, 'chown', 'www-data:www-data', directory);
    try {
      if (credentials) await writeCredentials();
      prepared = true;
      await control('setup');
    } catch (error) {
      await cleanup();
      throw error;
    }
  };
  if (!config.isInteractive) on('before:run', prepare);
  on('after:run', cleanup);
  on('task', { pluginV3Failure: ({ title, error }) => {
    console.error(redact(`${title}\n${error}`));
    return null;
  }, pluginV3: async ({ action, ...data }) => {
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
