const fs = require('node:fs/promises');
const path = require('node:path');
const {createHash} = require('node:crypto');
const {loadCredentials} = require('../local/plugin-v3/credentials');
const root = path.resolve(__dirname, '../..');
const defaultTarget = 'https://woo-staging.do.taxcloud.net/';
function target(value = defaultTarget) {
  const u = new URL(value);
  const isLocal = u.hostname.endsWith('.local') || u.hostname === 'localhost' || u.hostname === '127.0.0.1';
  if ((u.protocol !== 'https:' && !(isLocal && u.protocol === 'http:')) || u.username || u.password || u.search || u.hash) {
    throw new Error('Use an HTTPS target URL without credentials, query or fragment.');
  }
  u.pathname = u.pathname.replace(/\/*$/, '/');
  return {url: u.href, id: createHash('sha256').update(u.href).digest('hex').slice(0, 12)};
}
function within(base, relative) {
  const u = new URL(relative, base.url);
  if (u.origin !== new URL(base.url).origin || !u.pathname.startsWith(new URL(base.url).pathname)) throw new Error('Refusing request outside the selected target.');
  return u.href;
}
function redactor(values) {
  const secrets = Object.values(values).filter(x => typeof x === 'string' && x.length > 2).sort((a,b)=>b.length-a.length);
  return data => {
    let value = typeof data === 'string' ? data : JSON.stringify(data);
    for (const secret of secrets) value = value.split(secret).join('[redacted]');
    value = value.replace(/(Bearer\s+)[A-Za-z0-9._~-]+/gi, '$1[redacted]').replace(/(\/connections\/)[^/\s"?]+/g,'$1:connection');
    return typeof data === 'string' ? value : JSON.parse(value);
  };
}
function privatePath(t) { return path.join(root, 'cypress/.staging-private', t.id, 'access.json'); }
async function access(t) {
  let a;
  try { a = JSON.parse(await fs.readFile(privatePath(t), 'utf8')); } catch { throw new Error(`Prepare the helper in logged-in @Chrome and import its private access download using test:staging:setup -- --access-file=/absolute/path/sst-qa-access.json for target ${t.url}`); }
  if (target(a.targetUrl).url !== t.url || !/^[\da-f-]{36}$/.test(a.runId) || !a.admin?.username || !a.admin?.password) throw new Error('Runner access does not match this target or is invalid.');
  await fs.chmod(privatePath(t), 0o600);
  return a;
}
class Client {
  constructor(t, a) { this.target = t; this.access = a; this.cookies = new Map(); }
  async request(relative, options = {}) {
    const url = within(this.target, relative);
    const response = await fetch(url, {...options, signal: AbortSignal.timeout(90000), redirect:'manual', headers:{...options.headers, Cookie:[...this.cookies].map(([k,v])=>`${k}=${v}`).join('; ')}});
    for (const cookie of response.headers.getSetCookie()) { const pair = cookie.split(';')[0]; const at = pair.indexOf('='); this.cookies.set(pair.slice(0,at), pair.slice(at+1)); }
    const location = response.headers.get('location');
    if (location) within(this.target, new URL(location, url).href);
    return response;
  }
  async login() {
    await this.request('wp-login.php');
    await this.request('wp-login.php', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({log:this.access.admin.username,pwd:this.access.admin.password,testcookie:'1','wp-submit':'Log In',redirect_to:within(this.target,'wp-admin/tools.php?page=sst-staging-qa')})});
    const r = await this.request('wp-admin/tools.php?page=sst-staging-qa');
    if (r.status !== 200) throw new Error('Cypress runner administrator login failed. Reprovision access in @Chrome.');
    const html = await r.text(); const match = html.match(/data-sst-nonce="([^"]+)"/);
    if (!match) throw new Error('Helper unavailable or runner session is not authenticated.');
    this.nonce = match[1];
  }
  async control(action, data = {}) {
    const options={method:'POST',headers:{'Content-Type':'application/json','X-WP-Nonce':this.nonce},body:JSON.stringify({action,runId:this.access.runId,...data})};
    let r;
    try { r=await this.request('?rest_route=/sst-qa/v1/control',options); }
    catch(error) {
      // A read-only helper call may recover once from a transient transport failure.
      // Never automatically repeat layout, restoration, order creation or refunds.
      if(!['info','records','order','remoteOrder'].includes(action))throw error;
      r=await this.request('?rest_route=/sst-qa/v1/control',options);
    }
    let body; try { body = await r.json(); } catch { throw new Error(`Helper returned non-JSON for ${action} (HTTP ${r.status}).`); }
    if (!r.ok) throw new Error(`Helper ${action}: HTTP ${r.status}; ${body.code || 'request_failed'}`);
    return body;
  }
}
module.exports = {root, target, within, redactor, access, privatePath, Client, loadCredentials};
