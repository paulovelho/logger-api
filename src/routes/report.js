const express = require('express');
const pool = require('../db');
const authenticate = require('../middleware/auth');

const router = express.Router();

router.get('/', authenticate, async (req, res) => {
  try {
    const { from, to, limit = 100, skip = 0 } = req.query;

    const fromVal = from || null;
    const toVal = to || null;

    const [logs] = await pool.execute(
      `SELECT id, user_id, data, timestamp
       FROM logger_logs
       WHERE user_id = ?
         AND (? IS NULL OR timestamp >= ?)
         AND (? IS NULL OR timestamp <= ?)
       ORDER BY timestamp DESC
       LIMIT ? OFFSET ?`,
      [req.userId, fromVal, fromVal, toVal, toVal, Number(limit), Number(skip)]
    );

    const [[{ total }]] = await pool.execute(
      `SELECT COUNT(*) AS total
       FROM logger_logs
       WHERE user_id = ?
         AND (? IS NULL OR timestamp >= ?)
         AND (? IS NULL OR timestamp <= ?)`,
      [req.userId, fromVal, fromVal, toVal, toVal]
    );

    const mapped = logs.map((row) => ({
      _id: row.id,
      userId: row.user_id,
      data: typeof row.data === 'string' ? JSON.parse(row.data) : row.data,
      timestamp: row.timestamp,
    }));

    res.json({ total, count: mapped.length, logs: mapped });
  } catch (err) {
    res.status(500).json({ error: 'Failed to fetch logs' });
  }
});

module.exports = router;
