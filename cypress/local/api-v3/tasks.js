const { spawn, execFileSync } = require('node:child_process');
const path = require('node:path');

function execute(command, args, input) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, { shell: false, stdio: ['pipe', 'pipe', 'pipe'] });
    let stdout = '';
    let stderr = '';
    const timer = setTimeout(() => {
      child.kill();
      reject(new Error(`${command} timed out running the V3 contract bridge.`));
    }, 45000);
    child.stdout.on('data', (chunk) => { stdout += chunk; });
    child.stderr.on('data', (chunk) => { stderr += chunk; });
    child.on('error', (error) => { clearTimeout(timer); reject(error); });
    child.stdin.on('error', () => {}); // Exit/error handlers report early process failures.
    child.on('close', (code) => {
      clearTimeout(timer);
      if (code !== 0) {
        reject(new Error(`${command} exited ${code}: ${stderr || stdout}`));
      } else {
        resolve(stdout.trim());
      }
    });
    child.stdin.end(input);
  });
}

module.exports = (on, config) => {
  const root = config.projectRoot;
  const backend = config.env.apiV3Backend || 'php';
  if (!['php', 'docker'].includes(backend)) {
    throw new Error('apiV3Backend must be php or docker.');
  }
  let container;
  const cleanup = () => {
    if (container) {
      const id = container;
      container = undefined;
      execFileSync('docker', ['rm', '--force', id], { stdio: 'ignore', timeout: 15000 });
    }
  };

  // Use the existing WordPress image's PHP, but an isolated read-only checkout.
  // The helper has no network, database, published ports, or WordPress bootstrap.
  on('before:run', async () => {
    if (backend === 'docker') {
      container = await execute('docker', [
        'run', '--detach', '--rm', '--network', 'none',
        '--mount', `type=bind,source=${root},target=/sst-tests,readonly`,
        '--entrypoint', 'php', config.env.apiV3DockerImage || 'docker-wordpress',
        '-r', 'sleep(86400);',
      ], '');
    }
  });
  on('after:run', cleanup);
  process.once('exit', cleanup);

  on('task', {
    async taxcloudV3Contract(input) {
      let output;
      if (backend === 'docker') {
        if (!container) {
          throw new Error('Docker backend requires cypress run. Use the PHP backend for cypress open.');
        }
        output = await execute('docker', [
          'exec', '--interactive', container, 'php', '/sst-tests/cypress/local/api-v3/client.php',
        ], JSON.stringify(input));
      } else {
        output = await execute(config.env.apiV3PhpBinary || 'php', [
          path.join(root, 'cypress/local/api-v3/client.php'),
        ], JSON.stringify(input));
      }
      return JSON.parse(output);
    },
  });
  return config;
};
