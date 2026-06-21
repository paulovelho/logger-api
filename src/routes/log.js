const express = require('express');
const pool = require('../db');
const authenticate = require('../middleware/auth');

const router = express.Router();

router.post('/', authenticate, async (req, res) => {
  try {
    const [result] = await pool.execute(
      'INSERT INTO logger_logs (user_id, data) VALUES (?, ?)',
      [req.userId, JSON.stringify(req.body)]
    );
    res.status(201).json({ id: result.insertId, timestamp: new Date() });
  } catch (err) {
    res.status(500).json({ error: 'Failed to save log' });
  }
});

module.exports = router;
