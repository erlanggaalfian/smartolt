// User management routes
const router = require('express').Router();
const pool = require('../db');
const bcrypt = require('bcryptjs');
const { superadminOnly } = require('../auth');
const { writeAuditLog } = require('../helpers');

// GET /api/user — list users
router.get('/', superadminOnly, async (req, res) => {
  const [users] = await pool.execute('SELECT id, username, role, created_at FROM users ORDER BY created_at DESC');
  res.json({ success: true, users });
});

// GET /api/user/:id/access — get OLT access for user
router.get('/:id/access', superadminOnly, async (req, res) => {
  const [rows] = await pool.execute('SELECT olt_id FROM user_olts WHERE user_id=?', [+req.params.id]);
  res.json({ success: true, olt_ids: rows.map(r => r.olt_id) });
});

// POST /api/user — add user
router.post('/', superadminOnly, async (req, res) => {
  const { username, password, role, olt_ids } = req.body;
  if (!username || !password || !role) return res.json({ success: false, message: 'Field wajib tidak lengkap!' });
  try {
    const hash = await bcrypt.hash(password, 10);
    const [result] = await pool.execute('INSERT INTO users (username, password, role) VALUES (?,?,?)', [username.trim(), hash, role]);
    if (role === 'biasa' && olt_ids?.length) {
      for (const oid of olt_ids) await pool.execute('INSERT INTO user_olts (user_id, olt_id) VALUES (?, ?)', [result.insertId, oid]);
    }
    await writeAuditLog(pool, null, 'USER_CREATE', `User ${username} (${role}) dibuat.`);
    res.json({ success: true, id: result.insertId });
  } catch (e) {
    if (e.code === 'ER_DUP_ENTRY') return res.json({ success: false, message: 'Username sudah digunakan.' });
    res.status(500).json({ success: false, message: e.message });
  }
});

// PUT /api/user/:id/access — update OLT access
router.put('/:id/access', superadminOnly, async (req, res) => {
  const { olt_ids } = req.body;
  const uid = +req.params.id;
  await pool.execute('DELETE FROM user_olts WHERE user_id=?', [uid]);
  if (olt_ids?.length) {
    for (const oid of olt_ids) await pool.execute('INSERT INTO user_olts (user_id, olt_id) VALUES (?, ?)', [uid, oid]);
  }
  res.json({ success: true });
});

// DELETE /api/user/:id
router.delete('/:id', superadminOnly, async (req, res) => {
  const uid = +req.params.id;
  const [user] = await pool.execute('SELECT username FROM users WHERE id=?', [uid]);
  if (!user[0]) return res.json({ success: false, message: 'User tidak ditemukan.' });
  await pool.execute('DELETE FROM user_olts WHERE user_id=?', [uid]);
  await pool.execute('DELETE FROM users WHERE id=?', [uid]);
  await writeAuditLog(pool, null, 'USER_DELETE', `User ${user[0].username} dihapus.`);
  res.json({ success: true });
});

module.exports = router;
