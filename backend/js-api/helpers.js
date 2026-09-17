// SmartOLT JS API — Shared Helpers
const crypto = require('crypto');
const http = require('http');

const APP_KEY = process.env.APP_KEY || 'smartolt-default-secret-change-me';
const ENGINE_PORT = parseInt(process.env.ENGINE_PORT || '8000');

// AES-256-CBC decrypt — matches PHP encrypt_password / Python decrypt_password
function decryptPassword(ciphertextB64) {
  if (!ciphertextB64) return '';
  try {
    const key = crypto.createHash('sha256').update(APP_KEY).digest();
    const data = Buffer.from(ciphertextB64, 'base64');
    if (data.length <= 16) return ciphertextB64;
    const iv = data.subarray(0, 16);
    const ciphertext = data.subarray(16);
    const decipher = crypto.createDecipheriv('aes-256-cbc', key, iv);
    let decrypted = Buffer.concat([decipher.update(ciphertext), decipher.finalize()]);
    // PKCS7 unpad
    const pad = decrypted[decrypted.length - 1];
    if (pad > 0 && pad <= 16) decrypted = decrypted.subarray(0, decrypted.length - pad);
    return decrypted.toString('utf8');
  } catch {
    return ciphertextB64; // fallback: not encrypted
  }
}

// HMAC token for Python engine (same format as PHP engine_token)
function engineToken() {
  const payload = Buffer.from(JSON.stringify({ uid: 0, role: 'superadmin', exp: Math.floor(Date.now() / 1000) + 300 })).toString('base64url');
  const sig = crypto.createHmac('sha256', APP_KEY).update(payload).digest();
  return `${payload}.${sig.toString('base64url')}`;
}

// Call Python engine REST API
function callEngine(method, olt, args = []) {
  return new Promise((resolve) => {
    const decryptedOlt = { ...olt };
    if (olt.password) decryptedOlt.password = decryptPassword(olt.password);

    const payload = JSON.stringify({ olt: decryptedOlt, method, args: args || [] });
    const req = http.request({
      hostname: '127.0.0.1',
      port: ENGINE_PORT,
      path: '/olt/call',
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-SmartOLT-Token': engineToken() },
      timeout: 60000,
    }, (res) => {
      let body = '';
      res.on('data', (c) => body += c);
      res.on('end', () => {
        try { resolve(JSON.parse(body)); }
        catch { resolve({ success: false, message: 'Engine response tidak valid.' }); }
      });
    });
    req.on('error', () => resolve({ success: false, message: 'Python Engine tidak merespons.' }));
    req.on('timeout', () => { req.destroy(); resolve({ success: false, message: 'Engine timeout.' }); });
    req.write(payload);
    req.end();
  });
}

// SNMP UDP ping (raw socket, same as PHP check_snmp_ping)
function checkSnmpPing(ip, port = 161, community = 'public', timeout = 1000) {
  return new Promise((resolve) => {
    const dgram = require('dgram');
    const client = dgram.createSocket('udp4');
    const commHex = Buffer.from(community, 'utf8').toString('hex');
    const commLen = Buffer.from(community, 'utf8').length;
    const versionBin = Buffer.from('020101', 'hex');
    const commBin = Buffer.concat([Buffer.from([0x04, commLen]), Buffer.from(community, 'utf8')]);
    const pdu = Buffer.from('a01c020412345678020100020100300e300c06082b060102010101000500', 'hex');
    const body = Buffer.concat([versionBin, commBin, pdu]);
    const packet = Buffer.concat([Buffer.from([0x30, body.length]), body]);

    const timer = setTimeout(() => { client.close(); resolve(false); }, timeout);
    client.send(packet, port, ip, (err) => {
      if (err) { clearTimeout(timer); client.close(); resolve(false); }
    });
    client.on('message', () => { clearTimeout(timer); client.close(); resolve(true); });
  });
}

// CLI-safe sanitization (matches PHP cli_safe)
function cliSafe(str) {
  if (!str) return '';
  return str.replace(/[;&|`$(){}[\]<>!#\\'"*?~]/g, '');
}

// Audit log
async function writeAuditLog(pool, oltId, action, message) {
  try {
    await pool.execute('INSERT INTO logs (olt_id, action, message) VALUES (?, ?, ?)', [oltId || null, action, message]);
  } catch { /* non-critical */ }
}

module.exports = { decryptPassword, engineToken, callEngine, checkSnmpPing, cliSafe, writeAuditLog };
