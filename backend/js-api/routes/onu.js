// ONU routes: data, signal, reboot, delete, authorize, config, state, replace
const router = require('express').Router();
const pool = require('../db');
const { callEngine, writeAuditLog, cliSafe } = require('../helpers');

// Helper: get ONU + OLT data
async function getOnuWithOlt(onuId, userId, role) {
  const [rows] = await pool.execute(
    `SELECT onus.*, olts.name AS olt_name, olts.ip AS olt_ip, olts.username AS olt_username,
            olts.password AS olt_password, olts.ssh_port, olts.type AS olt_type, olts.protocol,
            olts.snmp_port, olts.snmp_community, olts.snmp_community_rw
     FROM onus JOIN olts ON onus.olt_id = olts.id WHERE onus.id = ?`, [onuId]);
  if (!rows[0]) return null;
  if (role !== 'superadmin') {
    const [access] = await pool.execute('SELECT 1 FROM user_olts WHERE user_id = ? AND olt_id = ?', [userId, rows[0].olt_id]);
    if (!access.length) return null;
  }
  return rows[0];
}

function rowToOlt(row) {
  return { ip: row.olt_ip, username: row.olt_username, password: row.olt_password, ssh_port: row.ssh_port, type: row.olt_type, protocol: row.protocol || 'SSH', snmp_port: row.snmp_port || 161, snmp_community: row.snmp_community || 'public', snmp_community_rw: row.snmp_community_rw || 'public' };
}

// GET /api/onu/:id — full ONU data (realtime from OLT)
router.get('/:id', async (req, res) => {
  const row = await getOnuWithOlt(+req.params.id, req.user.uid, req.user.role);
  if (!row) return res.status(404).json({ success: false, message: 'ONU tidak ditemukan.' });
  const olt = rowToOlt(row);
  const r = await callEngine('getOnuFullStatus', olt, [{ pon_port: row.pon_port, onu_id: row.onu_id, serial_number: row.serial_number, name: row.name }]);
  if (r.success && r.data) {
    // Update cached signal in DB
    const rxOnu = r.data.rx_onu ?? r.data.optical?.rx_onu;
    const rxOlt = r.data.rx_olt ?? r.data.optical?.rx_olt;
    if (rxOnu !== undefined) {
      await pool.execute('UPDATE onus SET last_rx_power=?, last_rx_olt_power=?, updated_at=NOW() WHERE id=?',
        [parseFloat(rxOnu) || null, parseFloat(rxOlt) || null, row.id]).catch(() => {});
    }
  }
  res.json({ success: r.success, data: r.data || {}, message: r.message || '' });
});

// GET /api/onu/:id/signal — quick signal read
router.get('/:id/signal', async (req, res) => {
  const row = await getOnuWithOlt(+req.params.id, req.user.uid, req.user.role);
  if (!row) return res.status(404).json({ success: false, message: 'ONU tidak ditemukan.' });
  const olt = rowToOlt(row);
  const r = await callEngine('getOnuSignal', olt, [{ pon_port: row.pon_port, onu_id: row.onu_id, serial_number: row.serial_number }]);
  if (r.success) {
    await pool.execute('UPDATE onus SET last_rx_power=?, last_rx_olt_power=?, status=?, updated_at=NOW() WHERE id=?',
      [parseFloat(r.rx_onu) || null, parseFloat(r.rx_olt) || null, r.status || 'offline', row.id]).catch(() => {});
  }
  res.json(r);
});

// GET /api/onu/:id/config — running config
router.get('/:id/config', async (req, res) => {
  const row = await getOnuWithOlt(+req.params.id, req.user.uid, req.user.role);
  if (!row) return res.status(404).json({ success: false, message: 'ONU tidak ditemukan.' });
  res.json(await callEngine('getOnuRunningConfig', rowToOlt(row), [{ pon_port: row.pon_port, onu_id: row.onu_id, serial_number: row.serial_number }]));
});

// POST /api/onu/:id/reboot
router.post('/:id/reboot', async (req, res) => {
  const row = await getOnuWithOlt(+req.params.id, req.user.uid, req.user.role);
  if (!row) return res.status(404).json({ success: false, message: 'ONU tidak ditemukan.' });
  const r = await callEngine('rebootOnu', rowToOlt(row), [{ pon_port: row.pon_port, onu_id: row.onu_id, serial_number: row.serial_number }]);
  if (r.success) {
    await pool.execute("UPDATE onus SET status='offline', last_rx_power=NULL, pppoe_ip=NULL, mgmt_ip=NULL WHERE id=?", [row.id]);
    await writeAuditLog(pool, row.olt_id, 'ONU_REBOOT', `ONU ${row.name} (${row.serial_number}) di-reboot.`);
  }
  res.json(r);
});

// DELETE /api/onu/:id
router.delete('/:id', async (req, res) => {
  const row = await getOnuWithOlt(+req.params.id, req.user.uid, req.user.role);
  if (!row) return res.status(404).json({ success: false, message: 'ONU tidak ditemukan.' });
  const r = await callEngine('deleteOnu', rowToOlt(row), [{ pon_port: row.pon_port, onu_id: row.onu_id, serial_number: row.serial_number }]);
  if (r.success) {
    await pool.execute('DELETE FROM onus WHERE id=?', [row.id]);
    await writeAuditLog(pool, row.olt_id, 'ONU_DELETE', `ONU ${row.name} (${row.serial_number}) dihapus.`);
  }
  res.json(r);
});

// POST /api/onu/:id/state — enable/disable
router.post('/:id/state', async (req, res) => {
  const { action } = req.body; // 'enable' or 'disable'
  if (!['enable', 'disable'].includes(action)) return res.json({ success: false, message: 'Aksi tidak valid.' });
  const row = await getOnuWithOlt(+req.params.id, req.user.uid, req.user.role);
  if (!row) return res.status(404).json({ success: false, message: 'ONU tidak ditemukan.' });
  const method = action === 'enable' ? 'enableOnu' : 'disableOnu';
  const r = await callEngine(method, rowToOlt(row), [{ pon_port: row.pon_port, onu_id: row.onu_id, serial_number: row.serial_number }]);
  if (r.success) {
    const status = action === 'disable' ? 'disabled' : 'offline';
    await pool.execute('UPDATE onus SET status=? WHERE id=?', [status, row.id]);
    await writeAuditLog(pool, row.olt_id, action === 'enable' ? 'ONU_ENABLE' : 'ONU_DISABLE', `ONU ${row.name} ${action === 'enable' ? 'diaktifkan' : 'dinonaktifkan'}.`);
  }
  res.json(r);
});

// POST /api/onu/:id/config — configure ONU (WAN/PPPoE/etc)
router.post('/:id/config', async (req, res) => {
  const row = await getOnuWithOlt(+req.params.id, req.user.uid, req.user.role);
  if (!row) return res.status(404).json({ success: false, message: 'ONU tidak ditemukan.' });
  const wan = req.body;
  const r = await callEngine('configureOnuFull', rowToOlt(row), [{ pon_port: row.pon_port, onu_id: row.onu_id, serial_number: row.serial_number, name: row.name }, wan]);
  if (r.success) {
    // Update DB with new config
    await pool.execute('UPDATE onus SET vlan=?, pppoe_username=?, pppoe_password=?, onu_mode=?, wan_mode=?, config_method=?, ip_protocol=?, wan_remote_access=?, mgmt_ip_mode=?, mgmt_ip=?, mgmt_vlan=?, allow_remote_mgmt=? WHERE id=?',
      [wan.vlan_service || row.vlan, wan.pppoe_username || row.pppoe_username, wan.pppoe_password || row.pppoe_password, wan.onu_mode || row.onu_mode, wan.wan_mode || row.wan_mode, wan.config_method || row.config_method, wan.ip_protocol || row.ip_protocol, wan.wan_remote_access || row.wan_remote_access, wan.mgmt_ip_mode || row.mgmt_ip_mode, wan.mgmt_ip || row.mgmt_ip, wan.mgmt_vlan || row.mgmt_vlan, wan.allow_remote_mgmt || row.allow_remote_mgmt, row.id]);
    await writeAuditLog(pool, row.olt_id, 'ONU_CONFIG', `ONU ${row.name} dikonfigurasi ulang.`);
  }
  res.json(r);
});

// POST /api/onu/authorize — authorize new ONU
router.post('/authorize', async (req, res) => {
  const { olt_id, pon_port, serial_number, name, vlan, onu_type, zone, splitter, address, contact } = req.body;
  if (!olt_id || !pon_port || !serial_number) return res.json({ success: false, message: 'Data tidak lengkap.' });
  const [oltRows] = await pool.execute('SELECT * FROM olts WHERE id=?', [olt_id]);
  if (!oltRows[0]) return res.json({ success: false, message: 'OLT tidak ditemukan.' });
  const olt = oltRows[0];
  const safeName = cliSafe(name) || 'None';
  const desc = `zone_${cliSafe(zone) || 'None'}_descr_${cliSafe(address) || 'None'}_odb_${cliSafe(splitter) || 'None'}_contact_${cliSafe(contact) || 'None'}_name_${safeName}`;
  const r = await callEngine('authorizeOnu', olt, [pon_port, serial_number, safeName, parseInt(vlan) || null, desc, null]);
  if (r.success) {
    const onuId = r.onu_id;
    const pon = r.pon_port || pon_port;
    await pool.execute('INSERT INTO onus (olt_id, pon_port, onu_id, name, serial_number, vlan, status, onu_type, zone, splitter, address, contact) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
      [olt_id, pon, onuId, safeName, serial_number, parseInt(vlan) || null, 'offline', onu_type || 'ALL-ONT', zone || 'None', splitter || 'None', address || 'None', contact || 'None']);
    await writeAuditLog(pool, olt_id, 'ONU_AUTH', `ONU ${safeName} (${serial_number}) diotorisasi di ${pon_port}.`);
  }
  res.json(r);
});

// POST /api/onu/:id/replace — replace ONU serial
router.post('/:id/replace', async (req, res) => {
  const { new_serial_number } = req.body;
  if (!new_serial_number) return res.json({ success: false, message: 'Serial number baru wajib diisi.' });
  const row = await getOnuWithOlt(+req.params.id, req.user.uid, req.user.role);
  if (!row) return res.status(404).json({ success: false, message: 'ONU tidak ditemukan.' });
  const olt = rowToOlt(row);
  // Delete old
  const delR = await callEngine('deleteOnu', olt, [{ pon_port: row.pon_port, onu_id: row.onu_id, serial_number: row.serial_number }]);
  if (!delR.success) return res.json({ success: false, message: 'Gagal menghapus ONU lama: ' + delR.message });
  // Re-authorize with new serial
  const desc = `zone_${row.zone || 'None'}_descr_${row.address || 'None'}_odb_${row.splitter || 'None'}_contact_${row.contact || 'None'}_name_${row.name}`;
  const authR = await callEngine('authorizeOnu', olt, [row.pon_port, new_serial_number, row.name, row.vlan, desc, row.onu_id]);
  if (authR.success) {
    await pool.execute('UPDATE onus SET serial_number=?, status=?, last_rx_power=NULL WHERE id=?', [new_serial_number, 'offline', row.id]);
    await writeAuditLog(pool, row.olt_id, 'ONU_REPLACE', `ONU ${row.name}: serial ${row.serial_number} → ${new_serial_number}.`);
  }
  res.json(authR);
});

// GET /api/onu/:id/next-id — next free ONU ID on PON port
router.get('/next-id/:olt_id/:pon_port', async (req, res) => {
  const [oltRows] = await pool.execute('SELECT * FROM olts WHERE id=?', [+req.params.olt_id]);
  if (!oltRows[0]) return res.json({ success: false, message: 'OLT tidak ditemukan.' });
  res.json(await callEngine('getNextFreeOnuId', oltRows[0], [req.params.pon_port]));
});

// GET /api/onu/signals/:olt_id — bulk signal from DB cache
router.get('/signals/:olt_id', async (req, res) => {
  try {
    const oltId = +req.params.olt_id;
    const [onus] = await pool.execute('SELECT id, status, vlan, last_rx_power, last_rx_olt_power, updated_at FROM onus WHERE olt_id=?', [oltId]);
    res.json({ success: true, onus: onus.map(o => ({ id: o.id, status: o.status, vlan: o.vlan, rx_onu: o.last_rx_power != null ? Number(o.last_rx_power).toFixed(2) : 'N/A', rx_olt: o.last_rx_olt_power != null ? Number(o.last_rx_olt_power).toFixed(2) : 'N/A', updated_at: o.updated_at })) });
  } catch (e) { res.status(500).json({ success: false, message: e.message }); }
});

module.exports = router;
