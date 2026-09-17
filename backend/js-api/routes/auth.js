// Auth routes: login, register, me
const router = require('express').Router();
const bcrypt = require('bcryptjs');
const pool = require('../db');
const { mintToken, authMiddleware } = require('../auth');
const { writeAuditLog } = require('../helpers');

// POST /api/auth/login
router.post('/login', async (req, res) => {
  const { username, password } = req.body;
  if (!username || !password) return res.json({ success: false, message: 'Username dan password wajib diisi!' });
  try {
    const [rows] = await pool.execute('SELECT * FROM users WHERE username = ?', [username.trim()]);
    const user = rows[0];
    if (!user || !await bcrypt.compare(password, user.password)) {
      return res.json({ success: false, message: 'Username atau password salah!' });
    }
    const token = mintToken(user.id, user.role);
    await writeAuditLog(pool, null, 'USER_LOGIN', `User ${username} (${user.role}) berhasil login.`);
    res.json({ success: true, token, user: { id: user.id, username: user.username, role: user.role } });
  } catch (e) {
    res.status(500).json({ success: false, message: 'Kesalahan sistem.' });
  }
});

// POST /api/auth/register (first user = superadmin, after that superadmin-only)
router.post('/register', async (req, res) => {
  const { username, password, role } = req.body;
  if (!username || !password) return res.json({ success: false, message: 'Username dan password wajib diisi!' });
  try {
    const [countRows] = await pool.execute('SELECT COUNT(*) as c FROM users');
    const isFirst = countRows[0].c === 0;
    const finalRole = isFirst ? 'superadmin' : (role || 'biasa');

    const hash = await bcrypt.hash(password, 10);
    const [result] = await pool.execute('INSERT INTO users (username, password, role) VALUES (?, ?, ?)', [username.trim(), hash, finalRole]);
    await writeAuditLog(pool, null, isFirst ? 'ADMIN_SETUP' : 'USER_CREATE', `User ${username} (${finalRole}) dibuat.`);
    const token = mintToken(result.insertId, finalRole);
    res.json({ success: true, token, user: { id: result.insertId, username: username.trim(), role: finalRole } });
  } catch (e) {
    if (e.code === 'ER_DUP_ENTRY') return res.json({ success: false, message: 'Username sudah digunakan.' });
    res.status(500).json({ success: false, message: 'Kesalahan sistem.' });
  }
});

// GET /api/auth/me — verify token & return user info
router.get('/me', authMiddleware, (req, res) => {
  res.json({ success: true, user: req.user });
});

module.exports = router;
