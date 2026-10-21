// Keep suite selection separate from forwarded --env/--config Cypress options.
const {spawn} = require('node:child_process');
const path = require('node:path');
const [suite = 'plugin', ...options] = process.argv.slice(2);
if (!['admin', 'plugin', 'contracts', 'staging'].includes(suite)) {
  console.error('Choose admin, plugin, contracts or staging.');
  process.exit(1);
}
const live = options.includes('--live');
if (live && suite !== 'plugin') {
  console.error('--live is for the plugin suite.');
  process.exit(1);
}
const open = options.includes('--open');
const forwarded = options.filter(value => !['--live', '--open'].includes(value));
const mode = live ? 'live' : 'fixture';
console.log(`Cypress suite: ${suite}${suite === 'plugin' ? ` (${mode})` : ''}`);
const child = spawn(process.execPath, [
  path.join(path.dirname(require.resolve('cypress')), 'bin/cypress'),
  open ? 'open' : 'run', ...(open ? ['--e2e'] : []), ...forwarded,
], {
  stdio: 'inherit',
  env: {...process.env, CYPRESS_suite: suite, CYPRESS_mode: mode,
    ...(live ? {CYPRESS_taxcloudTestModeConfirmed: 'true'} : {})},
});
child.on('error', error => {console.error(error.message); process.exitCode = 1;});
child.on('exit', code => {process.exitCode = code === null ? 1 : code;});
