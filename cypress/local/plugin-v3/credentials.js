const fs = require('node:fs/promises');
const path = require('node:path');

const fields = ['apiLoginIDRealtime', 'apiKeyRealtime', 'apiLoginIDDataImport', 'apiKeyDataImport'];

async function loadCredentials(root, filename = 'cypress/credentials.local.json') {
  let values;
  try {
    values = JSON.parse(await fs.readFile(path.resolve(root, filename), 'utf8'));
  } catch (error) {
    // JSON parser errors can contain the credential text itself.
    throw new Error(error.code === 'ENOENT'
      ? 'Copy cypress/credentials.example.json to cypress/credentials.local.json and set both connections before running live plugin tests.'
      : 'Cannot read plugin V3 credentials. Check that the local credentials file contains valid JSON.');
  }
  if (!values || typeof values !== 'object' || Array.isArray(values)) {
    throw new Error('Plugin V3 credentials must be a JSON object.');
  }
  const credentials = {};
  for (const field of fields) {
    if (typeof values[field] !== 'string' || !values[field].trim() || /^YOUR_/i.test(values[field].trim())) {
      throw new Error(`Set ${field} in the local plugin V3 credentials file.`);
    }
    credentials[field] = values[field].trim();
  }
  return credentials;
}

module.exports = { loadCredentials };
