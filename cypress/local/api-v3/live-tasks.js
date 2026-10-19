const https = require('node:https');
const fs = require('node:fs');
const path = require('node:path');
const { execFile } = require('node:child_process');
const { promisify } = require('node:util');
const exec = promisify(execFile);

// Deliberately fixed: live tests cannot be redirected to production.
const apiBase = 'https://api.v3.taxcloud.net';
const authUrl = 'https://staging-taxcloudapi.azurewebsites.net/api/v3/auth/token';

function request(url, method, body, token) {
  return new Promise((resolve, reject) => {
    const payload = body === undefined ? undefined : JSON.stringify(body);
    const headers = { 'Content-Type': 'application/json', 'User-Agent': 'SimpleSalesTax/Cypress-staging-tests' };
    if (token) headers.Authorization = `Bearer ${token}`;
    if (payload !== undefined) headers['Content-Length'] = Buffer.byteLength(payload);
    const req = https.request(url, { method, headers }, (response) => {
      let text = '';
      response.setEncoding('utf8');
      response.on('data', (chunk) => { text += chunk; });
      response.on('end', () => {
        let parsed = text;
        try { parsed = text ? JSON.parse(text) : null; } catch (_) { /* Report non-JSON responses as text. */ }
        resolve({ status: response.statusCode, body: parsed });
      });
    });
    req.setTimeout(60000, () => { req.destroy(new Error('TaxCloud staging request timed out.')); });
    req.on('error', reject);
    req.end(payload);
  });
}

function createClient(root, env = {}) {
  let credentials;
  let token;
  let source;
  const loadCredentials = async () => {
    if (credentials) return;
    const file = path.join(root, 'cypress.api-v3.staging.env.json');
    if (process.env.TAXCLOUD_V3_LOGIN_ID || process.env.TAXCLOUD_V3_API_KEY) {
      credentials = {
        apiLoginID: process.env.TAXCLOUD_V3_LOGIN_ID,
        apiKey: process.env.TAXCLOUD_V3_API_KEY,
        connectionId: process.env.TAXCLOUD_V3_CONNECTION_ID || process.env.TAXCLOUD_V3_API_KEY,
      };
      source = 'environment variables';
    } else if (fs.existsSync(file)) {
      credentials = JSON.parse(fs.readFileSync(file, 'utf8'));
      source = 'ignored staging credentials file';
    } else {
      const script = '$s=get_option("woocommerce_wootax_settings",array()); echo wp_json_encode(array("apiLoginID"=>$s["tc_id"]??"","apiKey"=>$s["tc_key"]??""));';
      const { stdout } = await exec('docker', [
        'exec', env.apiV3WordpressContainer || 'docker-wordpress-1', 'wp', 'eval', script,
        '--allow-root', '--skip-plugins', '--skip-themes',
      ], { timeout: 30000 });
      credentials = JSON.parse(stdout);
      source = 'local Docker WordPress settings';
    }
    if (!credentials.apiLoginID || !credentials.apiKey) {
      throw new Error('Set staging apiLoginID and apiKey in cypress.api-v3.staging.env.json or TAXCLOUD_V3_LOGIN_ID/TAXCLOUD_V3_API_KEY.');
    }
    credentials.connectionId = credentials.connectionId || credentials.apiKey;
  };
  const redact = (body) => {
    let text = JSON.stringify(body);
    for (const secret of [credentials.apiLoginID, credentials.apiKey, token]) {
      if (secret) text = text.split(secret).join('[redacted]');
    }
    return JSON.parse(text);
  };
  return {
    async authenticate() {
      await loadCredentials();
      const response = await request(authUrl, 'POST', {
        apiLoginID: credentials.apiLoginID, apiKey: credentials.apiKey,
      });
      token = response.body && response.body.access_token;
      if (response.status !== 200 || typeof token !== 'string' || !token) {
        throw new Error(`Staging authentication failed (HTTP ${response.status}, credentials from ${source}): ${JSON.stringify(redact(response.body))}`);
      }
      return { status: response.status, hasAccessToken: true, source, environment: 'staging' };
    },
    async call({ method, route, body, authenticated = true }) {
      if (!token) throw new Error('Authenticate with TaxCloud staging before running API tests.');
      if (!['GET', 'POST', 'PATCH', 'DELETE'].includes(method) || !/^\/(tax|mgmt)\//.test(route) || route.includes('://') || route.includes('..')) {
        throw new Error('Only staging /tax/ and /mgmt/ API routes are allowed.');
      }
      const url = new URL(apiBase + route.replace(':connectionId', encodeURIComponent(credentials.connectionId)));
      if (url.origin !== apiBase) throw new Error('Only the staging TaxCloud host is allowed.');
      const response = await request(url, method, body, authenticated ? token : undefined);
      return { ...response, body: redact(response.body) };
    },
  };
}

module.exports = (on, config) => {
  const client = createClient(config.projectRoot, config.env);
  on('task', {
    taxcloudV3StagingAuthenticate: () => client.authenticate(),
    taxcloudV3StagingRequest: (input) => client.call(input),
  });
  return config;
};
module.exports.createClient = createClient;
