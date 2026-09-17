// VLAN routes: list, add, delete, port config
const router = require('express').Router();
const pool = require('../db');
const { superadminOnly } = require('../auth');
const { callEngine } = require('../helpers');

async function getOlt(id) {
  const [rows] = await pool.execute('SELECT * FROM olts WHERE id=?', [id]);
  return rows[0] || null;
}

// GET /api/vlan/:olt_id — list VLANs
router.get('/:olt_id', async (req, res) => {
  const olt = await getOlt(+req.params.olt_id);
  if (!olt) return res.json({ success: false, message: 'OLT tidak ditemukan.' });
  res.json(await callEngine('getVlans', olt));
});

// POST /api/vlan/:olt_id — add VLAN
router.post('/:olt_id', superadminOnly, async (req, res) => {
  const { vlan_id, description } = req.body;
  const olt = await getOlt(+req.params.olt_id);
  if (!olt) return res.json({ success: false, message: 'OLT tidak ditemukan.' });
  res.json(await callEngine('addVlan', olt, [parseInt(vlan_id), description || '']));
});

// DELETE /api/vlan/:olt_id/:vlan_id — delete VLAN
router.delete('/:olt_id/:vlan_id', superadminOnly, async (req, res) => {
  const olt = await getOlt(+req.params.olt_id);
  if (!olt) return res.json({ success: false, message: 'OLT tidak ditemukan.' });
  res.json(await callEngine('deleteVlan', olt, [+req.params.vlan_id, true]));
});

// POST /api/vlan/:olt_id/ports — save VLAN port config
router.post('/:olt_id/ports', superadminOnly, async (req, res) => {
  const { vlan_id, description, ports_config } = req.body;
  const olt = await getOlt(+req.params.olt_id);
  if (!olt) return res.json({ success: false, message: 'OLT tidak ditemukan.' });
  res.json(await callEngine('saveVlanPorts', olt, [vlan_id, description || '', ports_config || {}]));
});

// GET /api/vlan/:olt_id/interfaces — get interfaces
router.get('/:olt_id/interfaces', async (req, res) => {
  const olt = await getOlt(+req.params.olt_id);
  if (!olt) return res.json({ success: false, message: 'OLT tidak ditemukan.' });
  res.json(await callEngine('getInterfaces', olt));
});

// POST /api/vlan/:olt_id/port-vlan — set port VLAN mode
router.post('/:olt_id/port-vlan', superadminOnly, async (req, res) => {
  const { port, mode, tagged, untagged, pvid } = req.body;
  const olt = await getOlt(+req.params.olt_id);
  if (!olt) return res.json({ success: false, message: 'OLT tidak ditemukan.' });
  res.json(await callEngine('configPortVlan', olt, [port, mode, tagged, untagged, pvid]));
});

// POST /api/vlan/:olt_id/port-config — set port physical config
router.post('/:olt_id/port-config', superadminOnly, async (req, res) => {
  const { port, auto_nego, speed, duplex } = req.body;
  const olt = await getOlt(+req.params.olt_id);
  if (!olt) return res.json({ success: false, message: 'OLT tidak ditemukan.' });
  res.json(await callEngine('configPort', olt, [port, auto_nego, speed, duplex]));
});

module.exports = router;
