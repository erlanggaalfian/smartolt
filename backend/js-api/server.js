// SmartOLT JS API — Express Server
require('dotenv').config({ path: require('path').join(__dirname, '../../.env') });

const express = require('express');
const cors = require('cors');
const { authMiddleware } = require('./auth');

const app = express();
app.use(cors());
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

// Health check (no auth)
app.get('/api/health', (req, res) => res.json({ status: 'ok', engine: 'JS API' }));

// Public routes (no auth)
app.use('/api/auth', require('./routes/auth'));

// Protected routes (JWT required)
app.use('/api/olt', authMiddleware, require('./routes/olt'));
app.use('/api/onu', authMiddleware, require('./routes/onu'));
app.use('/api/vlan', authMiddleware, require('./routes/vlan'));
app.use('/api/user', authMiddleware, require('./routes/user'));
app.use('/api/logs', authMiddleware, require('./routes/logs'));

const PORT = parseInt(process.env.JS_API_PORT || '3002');
app.listen(PORT, '127.0.0.1', () => {
  console.log(`[SmartOLT JS API] Listening on http://127.0.0.1:${PORT}`);
});
