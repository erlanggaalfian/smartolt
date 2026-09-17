// OLT routes: CRUD, test, sync, autofind, panels, health, interfaces
const router = require('express').Router();
const pool = require('../db');
const { superadminOnly } = require('../auth');
const { callEngine, checkSnmpPing, decryptPassword, writeAuditLog, cliSafe } = require('../helpers');

// Helper: get OLT by ID with access check
async function getOlt(id, userId, role) {
  const [rows] = await pool.execute('SELECT * FROM olts WHERE id = ?', [id]);
  if (!rows[0]) return null;
  if (role !== 'superadmin') {
    const [access] = await pool.execute('SELECT 1 FROM user_olts WHERE user_id = ? AND olt_id = ?', [userId, id]);
    if (!access.length) return null;
  }
  return rows[0];
}

// GET /api/olt — list all OLTs (filtered by access)
router.get('/', async (req, res) => {
  try {
    let olts;
    if (req.user.role === 'superadmin') {
      [olts] = await pool.execute('SELECT * FROM olts ORDER BY created_at DESC');
    } else {
      [olts] = await pool.execute('SELECT o.* FROM olts o JOIN user_olts uo ON o.id = uo.olt_id WHERE uo.user_id = ? ORDER BY o.created_at DESC', [req.user.uid]);
    }
    res.json({ success: true, olts });
  } catch (e) { res.status(500).json({ success: false, message: e.message }); }
});

// GET /api/olt/types — supported OLT types
router.get('/types', (req, res) => {
  const registry = require('../registry');
  res.json({ success: true, types: registry });
});

// GET /api/olt/:id — single OLT
router.get('/:id', async (req, res) => {
  const olt = await getOlt(+req.params.id, req.user.uid, req.user.role);
  if (!olt) return res.status(404).json({ success: false, message: 'OLT tidak ditemukan.' });
  res.json({ success: true, olt });
});

// POST /api/olt — add OLT
router.post('/', superadminOnly, async (req, res) => {
  const { name, type, ip, protocol, ssh_port, username, password, snmp_port } = req.body;
  if (!name || !ip || !username || !password) return res.json({ success: false, message: 'Field wajib tidak lengkap!' });
  const registry = require('../registry');
  if (!registry[type]) return res.json({ success: false, message: 'Tipe OLT tidak didukung.' });
  try {
    const sp = parseInt(snmp_port) || 8161;
    const port = parseInt(ssh_port) || (protocol === 'TELNET' ? 23 : 22);
    const proto = protocol || 'SSH';
    const snmpRo = 'smartro_' + Math.random().toString(36).slice(2, 10);
    const snmpRw = 'smartrw_' + Math.random().toString(36).slice(2, 10);
    // Encrypt password (AES-256-CBC, same as PHP)
    const crypto = require('crypto');
    const key = crypto.createHash('sha256').update(process.env.APP_KEY || '').digest();
    const iv = crypto.randomBytes(16);
    const cipher = crypto.createCipheriv('aes-256-cbc', key, iv);
    const encrypted = Buffer.concat([iv, cipher.update(password, 'utf8'), cipher.finalize()]).toString('base64');

    const [result] = await pool.execute(
      'INSERT INTO olts (name, ip, ssh_port, username, password, protocol, snmp_port, snmp_community, snmp_community_rw, type) VALUES (?,?,?,?,?,?,?,?,?,?)',
      [name, ip, port, username, encrypted, proto, sp, snmpRo, snmpRw, type]
    );
    await writeAuditLog(pool, result.insertId, 'OLT_ADD', `OLT ${name} (${ip}) ditambahkan.`);
    res.json({ success: true, id: result.insertId });
  } catch (e) {
    if (e.code === 'ER_DUP_ENTRY') return res.json({ success: false, message: 'IP OLT sudah terdaftar.' });
    res.status(500).json({ success: false, message: e.message });
  }
});

// PUT /api/olt/:id — edit OLT
router.put('/:id', superadminOnly, async (req, res) => {
  const id = +req.params.id;
  const { name, type, ip, protocol, ssh_port, username, password, snmp_port, snmp_community, snmp_community_rw } = req.body;
  const registry = require('../registry');
  if (!registry[type]) return res.json({ success: false, message: 'Tipe OLT tidak didukung.' });
  try {
    const [old] = await pool.execute('SELECT * FROM olts WHERE id = ?', [id]);
    if (!old[0]) return res.json({ success: false, message: 'OLT tidak ditemukan.' });
    const port = parseInt(ssh_port) || (protocol === 'TELNET' ? 23 : 22);
    let enc = old[0].password;
    if (password) {
      const crypto = require('crypto');
      const key = crypto.createHash('sha256').update(process.env.APP_KEY || '').digest();
      const iv = crypto.randomBytes(16);
      const cipher = crypto.createCipheriv('aes-256-cbc', key, iv);
      enc = Buffer.concat([iv, cipher.update(password, 'utf8'), cipher.finalize()]).toString('base64');
    }
    await pool.execute(
      'UPDATE olts SET name=?, type=?, ip=?, ssh_port=?, username=?, password=?, protocol=?, snmp_port=?, snmp_community=?, snmp_community_rw=? WHERE id=?',
      [name, type, ip, port, username, enc, protocol || 'SSH', parseInt(snmp_port) || 8161, snmp_community || old[0].snmp_community, snmp_community_rw || old[0].snmp_community_rw, id]
    );
    await writeAuditLog(pool, id, 'OLT_EDIT', `OLT ${name} (${ip}) diperbarui.`);
    res.json({ success: true });
  } catch (e) { res.status(500).json({ success: false, message: e.message }); }
});

// DELETE /api/olt/:id
router.delete('/:id', superadminOnly, async (req, res) => {
  try {
    const [old] = await pool.execute('SELECT * FROM olts WHERE id = ?', [+req.params.id]);
    if (!old[0]) return res.json({ success: false, message: 'OLT tidak ditemukan.' });
    await pool.execute('DELETE FROM olts WHERE id = ?', [+req.params.id]);
    await writeAuditLog(pool, null, 'OLT_DELETE', `OLT ${old[0].name} (${old[0].ip}) dihapus.`);
    res.json({ success: true });
  } catch (e) { res.status(500).json({ success: false, message: e.message }); }
});

// GET /api/olt/:id/test — test connection
router.get('/:id/test', superadminOnly, async (req, res) => {
  const olt = await getOlt(+req.params.id, req.user.uid, req.user.role);
  if (!olt) return res.status(404).json({ success: false, message: 'OLT tidak ditemukan.' });
  const r = await callEngine('checkConnection', olt);
  if (r.success) {
    r.snmp_ok = await checkSnmpPing(olt.ip, olt.snmp_port || 161, olt.snmp_community || 'public');
    r.message = r.message?.replace(/^.*SNMP Status.*$/mu, '').trim() + (r.snmp_ok ? '\n\n• SNMP Status: Koneksi Berhasil!' : '\n\n⚠️ SNMP Timeout.');
  }
  res.json(r);
});

// GET /api/olt/:id/health — health status
router.get('/:id/health', superadminOnly, async (req, res) => {
  const olt = await getOlt(+req.params.id, req.user.uid, req.user.role);
  if (!olt) return res.status(404).json({ success: false, message: 'OLT tidak ditemukan.' });
  const r = await callEngine('checkConnection', olt);
  const out = { ssh: r.success ? 'Connected' : 'Disconnected', cpu: 'N/A', ram: 'N/A', temp: 'N/A', uptime: 'N/A' };
  if (r.success && r.message) {
    const m = r.message;
    const cpu = m.match(/CPU Load\s*:\s*([^\n\r]+)/i); if (cpu) out.cpu = cpu[1].trim();
    const ram = m.match(/RAM Usage\s*:\s*([^\n\r]+)/i); if (ram) out.ram = ram[1].trim();
    const temp = m.match(/Temperature\s*:\s*([^\n\r]+)/i); if (temp) out.temp = temp[1].trim();
    const up = m.match(/Uptime\s*:\s*([^\n\r]+)/i); if (up) out.uptime = up[1].trim();
  }
  out.snmp = await checkSnmpPing(olt.ip, olt.snmp_port || 161, olt.snmp_community || 'public') ? 'Connected' : 'Disconnected';
  res.json({ success: true, ...out });
});

// GET /api/olt/:id/autofind — scan unconfigured ONUs
router.get('/:id/autofind', async (req, res) => {
  const olt = await getOlt(+req.params.id, req.user.uid, req.user.role);
  if (!olt) return res.status(404).json({ success: false, message: 'OLT tidak ditemukan.' });
  res.json(await callEngine('getAutofind', olt));
});

// GET /api/olt/:id/panel/:panel — panel data
router.get('/:id/panel/:panel', async (req, res) => {
  const olt = await getOlt(+req.params.id, req.user.uid, req.user.role);
  if (!olt) return res.status(404).json({ success: false, message: 'OLT tidak ditemukan.' });
  const panel = req.params.panel;
  if (!/^[a-z0-9\-]{1,32}$/.test(panel)) return res.json({ success: false, message: 'Nama panel tidak valid.' });
  res.json(await callEngine('getPanelData', olt, [panel]));
});

// POST /api/olt/:id/sync — sync ONU data
router.post('/:id/sync', async (req, res) => {
  const olt = await getOlt(+req.params.id, req.user.uid, req.user.role);
  if (!olt) return res.status(404).json({ success: false, message: 'OLT tidak ditemukan.' });
  const pullRes = await callEngine('pullConfiguredOnus', olt);
  if (!pullRes.success) return res.json(pullRes);
  const onus = pullRes.onus || [];
  let added = 0, updated = 0;
  for (const onu of onus) {
    const [existing] = await pool.execute('SELECT id FROM onus WHERE serial_number = ?', [onu.serial_number]);
    if (existing.length) {
      await pool.execute('UPDATE onus SET name=?, pon_port=?, onu_id=?, vlan=?, status=?, olt_id=? WHERE id=?',
        [onu.name, onu.pon_port, onu.onu_id, onu.vlan || null, onu.status || 'offline', olt.id, existing[0].id]);
      updated++;
    } else {
      await pool.execute('INSERT INTO onus (olt_id, pon_port, onu_id, name, serial_number, vlan, status) VALUES (?,?,?,?,?,?,?)',
        [olt.id, onu.pon_port, onu.onu_id, onu.name, onu.serial_number, onu.vlan || null, onu.status || 'offline']);
      added++;
    }
  }
  await writeAuditLog(pool, olt.id, 'OLT_SYNC', `Sync ${olt.name}: ${onus.length} ONU, +${added} baru, ${updated} diperbarui.`);
  res.json({ success: true, message: `Sync selesai: ${onus.length} ONU ditemukan, ${added} ditambahkan, ${updated} diperbarui.`, total: onus.length, added, updated });
});

// POST /api/olt/:id/verify-ssh — verify SSH connection only
router.post('/:id/verify-ssh', superadminOnly, async (req, res) => {
  const { ip, type, protocol, ssh_port, username, password } = req.body;
  if (!ip || !username || !password) return res.json({ success: false, message: 'IP, Username, Password wajib diisi!' });
  const olt = { ip, type, protocol: protocol || 'SSH', ssh_port: parseInt(ssh_port) || 22, username, password };
  res.json(await callEngine('checkConnection', olt));
});

module.exports = router;
