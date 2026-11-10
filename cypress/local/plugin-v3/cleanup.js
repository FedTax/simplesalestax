// Recovery for a stopped local run. Use only after its Cypress process exits.
const { execFile } = require('node:child_process');
const { promisify } = require('node:util');
const path = require('node:path');
const fs = require('node:fs/promises');
const exec = promisify(execFile);
const container = process.env.SST_E2E_WORDPRESS_CONTAINER || 'docker-wordpress-1';
const docker = (...args) => exec('docker', args, { timeout: 90000 });

(async () => {
  if (process.env.SST_E2E_WORDPRESS_PATH) {
    const root = path.resolve(process.env.SST_E2E_WORDPRESS_PATH);
    const wpCli = process.env.SST_E2E_WP_CLI || (await exec('which', ['wp'])).stdout.trim();
    const phpArgs = process.env.SST_E2E_MYSQL_SOCKET ? ['-d', `mysqli.default_socket=${process.env.SST_E2E_MYSQL_SOCKET}`] : [];
    const wp = (args, env = {}) => exec(process.env.SST_E2E_PHP_BINARY || 'php', [...phpArgs, wpCli, `--path=${root}`, ...args],
      { timeout: 90000, env: { ...process.env, ...env } });
    const { stdout } = await wp(['eval',
      'echo wp_json_encode(array_intersect_key(get_option("sst_cypress_v3_context",array()),array_flip(array("run","mode","localwp"))));',
      '--skip-plugins', '--skip-themes']);
    const context = JSON.parse(stdout);
    if (!context.run) { console.log('No interrupted Cypress run context exists.'); return; }
    if (!context.localwp || !/^[a-f0-9-]{36}$/.test(context.run)) throw new Error('Invalid LocalWP run context.');
    const directory = `/tmp/sst-cypress-${context.run}`;
    await fs.mkdir(directory, { recursive: true, mode: 0o700 });
    await fs.copyFile(path.join(__dirname, 'control.php'), `${directory}/control.php`);
    const payload = Buffer.from(JSON.stringify({ ...context, action: 'cleanup' })).toString('base64');
    await wp(['eval-file', `${directory}/control.php`], { SST_E2E_RUN: context.run, SST_E2E_PAYLOAD: payload });
    await fs.rm(path.join(root, 'wp-content/mu-plugins/sst-cypress-v3.php'), { force: true });
    await fs.rm(directory, { recursive: true, force: true });
    console.log('Removed the interrupted LocalWP run helper and disposable fixtures. Test orders/customers remain.');
    return;
  }
  const { stdout } = await docker('exec', container, 'wp', 'eval',
    'echo wp_json_encode(array_intersect_key(get_option("sst_cypress_v3_context",array()),array_flip(array("run","mode"))));',
    '--allow-root', '--skip-plugins', '--skip-themes');
  const context = JSON.parse(stdout);
  if (!context.run) {
    console.log('No interrupted Cypress run context exists.');
    return;
  }
  if (!/^[a-f0-9-]{36}$/.test(context.run)) throw new Error('Invalid saved run ID.');
  const directory = `/tmp/sst-cypress-${context.run}`;
  await docker('exec', container, 'mkdir', '-p', directory);
  await docker('cp', path.join(__dirname, 'control.php'), `${container}:${directory}/control.php`);
  const payload = Buffer.from(JSON.stringify({ ...context, action: 'cleanup' })).toString('base64');
  await docker('exec', '--env', `SST_E2E_RUN=${context.run}`, '--env', `SST_E2E_PAYLOAD=${payload}`,
    container, 'wp', 'eval-file', `${directory}/control.php`, '--allow-root');
  await docker('exec', container, 'rm', '-f', '/var/www/html/wp-content/mu-plugins/sst-cypress-v3.php');
  await docker('exec', container, 'rm', '-rf', directory);
  console.log('Removed the interrupted run helper and disposable fixtures. Test orders/customers remain.');
})().catch((error) => { console.error(error.message); process.exitCode = 1; });
