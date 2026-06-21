const express = require('express');
const pool = require('../db');

const router = express.Router();

router.get('/logs', async (req, res) => {
  try {
    const { userId, from, to, limit = 100, skip = 0 } = req.query;

    const userIdVal = userId || null;
    const fromVal = from || null;
    const toVal = to || null;

    const [logs] = await pool.execute(
      `SELECT id, user_id, data, timestamp
       FROM logger_logs
       WHERE (? IS NULL OR user_id = ?)
         AND (? IS NULL OR timestamp >= ?)
         AND (? IS NULL OR timestamp <= ?)
       ORDER BY timestamp DESC
       LIMIT ? OFFSET ?`,
      [userIdVal, userIdVal, fromVal, fromVal, toVal, toVal, Number(limit), Number(skip)]
    );

    const [[{ total }]] = await pool.execute(
      `SELECT COUNT(*) AS total
       FROM logger_logs
       WHERE (? IS NULL OR user_id = ?)
         AND (? IS NULL OR timestamp >= ?)
         AND (? IS NULL OR timestamp <= ?)`,
      [userIdVal, userIdVal, fromVal, fromVal, toVal, toVal]
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

router.get('/services', async (_req, res) => {
  try {
    const [services] = await pool.execute(
      `SELECT user_id AS userId, COUNT(*) AS count, MAX(timestamp) AS lastLog
       FROM logger_logs
       GROUP BY user_id
       ORDER BY lastLog DESC`
    );
    res.json({ services });
  } catch (err) {
    res.status(500).json({ error: 'Failed to fetch services' });
  }
});

module.exports = router;
