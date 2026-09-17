// SmartOLT JS API — JWT Auth Middleware
const jwt = require('jsonwebtoken');

const JWT_SECRET = process.env.APP_KEY || 'smartolt-default-secret-change-me';
const TOKEN_TTL = '24h';

function mintToken(userId, role) {
  return jwt.sign({ uid: userId, role }, JWT_SECRET, { expiresIn: TOKEN_TTL });
}

function authMiddleware(req, res, next) {
  const token = req.headers.authorization?.replace('Bearer ', '') || req.query.token;
  if (!token) return res.status(401).json({ success: false, message: 'Token tidak ditemukan.' });
  try {
    req.user = jwt.verify(token, JWT_SECRET);
    next();
  } catch {
    res.status(401).json({ success: false, message: 'Token tidak sah atau kedaluwarsa.' });
  }
}

function superadminOnly(req, res, next) {
  if (req.user?.role !== 'superadmin') {
    return res.status(403).json({ success: false, message: 'Akses ditolak. Hanya superadmin.' });
  }
  next();
}

module.exports = { mintToken, authMiddleware, superadminOnly };
