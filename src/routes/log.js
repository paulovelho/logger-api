const express = require('express');
const pool = require('../db');
const authenticate = require('../middleware/auth');

const router = express.Router();

router.post('/', authenticate, async (req, res) => {
  try {
    const { environment = 'unknown', ...data } = req.body;
    const [result] = await pool.execute(
      'INSERT INTO logger_logs (service, environment, data) VALUES (?, ?, ?)',
      [req.service, environment, JSON.stringify(data)]
    );
    res.status(201).json({ id: result.insertId, timestamp: new Date() });
  } catch (err) {
    res.status(500).json({ error: 'Failed to save log' });
  }
});

module.exports = router;
