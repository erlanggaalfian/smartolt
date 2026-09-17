// Logs routes
const router = require('express').Router();
const pool = require('../db');
const { superadminOnly } = require('../auth');

// GET /api/logs — list logs
router.get('/', async (req, res) => {
  try {
    const limit = Math.min(parseInt(req.query.limit) || 50, 500);
    const offset = parseInt(req.query.offset) || 0;
    let query, params;
    if (req.user.role === 'superadmin') {
      query = 'SELECT l.*, o.name AS olt_name FROM logs l LEFT JOIN olts o ON l.olt_id = o.id ORDER BY l.timestamp DESC LIMIT ? OFFSET ?';
      params = [limit, offset];
    } else {
      const [access] = await pool.execute('SELECT olt_id FROM user_olts WHERE user_id=?', [req.user.uid]);
      const ids = access.map(a => a.olt_id);
      if (!ids.length) return res.json({ success: true, logs: [] });
      query = `SELECT l.*, o.name AS olt_name FROM logs l LEFT JOIN olts o ON l.olt_id = o.id WHERE l.olt_id IS NULL OR l.olt_id IN (${ids.join(',')}) ORDER BY l.timestamp DESC LIMIT ? OFFSET ?`;
      params = [limit, offset];
    }
    const [logs] = await pool.execute(query, params);
    res.json({ success: true, logs });
  } catch (e) { res.status(500).json({ success: false, message: e.message }); }
});

// DELETE /api/logs — clear all logs
router.delete('/', superadminOnly, async (req, res) => {
  await pool.execute('TRUNCATE TABLE logs');
  res.json({ success: true });
});

module.exports = router;
