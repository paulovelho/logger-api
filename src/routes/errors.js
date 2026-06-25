const express = require('express');
const pool = require('../db');
const authenticate = require('../middleware/auth');

const router = express.Router();

router.get('/', authenticate, async (req, res) => {
  try {
    const { from, to, limit = 100, skip = 0 } = req.query;

    const fromVal = from || null;
    const toVal = to || null;

    const [errors] = await pool.execute(
      `SELECT id, service, environment, data, timestamp
       FROM logger_errors
       WHERE service = ?
         AND (? IS NULL OR timestamp >= ?)
         AND (? IS NULL OR timestamp <= ?)
       ORDER BY timestamp DESC
       LIMIT ? OFFSET ?`,
      [req.service, fromVal, fromVal, toVal, toVal, Number(limit), Number(skip)]
    );

    const [[{ total }]] = await pool.execute(
      `SELECT COUNT(*) AS total
       FROM logger_errors
       WHERE service = ?
         AND (? IS NULL OR timestamp >= ?)
         AND (? IS NULL OR timestamp <= ?)`,
      [req.service, fromVal, fromVal, toVal, toVal]
    );

    const mapped = errors.map((row) => ({
      _id: row.id,
      service: row.service,
      environment: row.environment,
      data: typeof row.data === 'string' ? JSON.parse(row.data) : row.data,
      timestamp: row.timestamp,
    }));

    res.json({ total, count: mapped.length, errors: mapped });
  } catch (err) {
    res.status(500).json({ error: 'Failed to fetch errors' });
  }
});

module.exports = router;
