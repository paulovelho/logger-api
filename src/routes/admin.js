const express = require('express');
const jwt = require('jsonwebtoken');
const pool = require('../db');
const authenticate = require('../middleware/auth');
const config = require('../../config.json');

const router = express.Router();

router.get('/logs', authenticate, async (req, res) => {
  try {
    const { service, from, to, limit = 100, skip = 0 } = req.query;

    const serviceVal = service || null;
    const fromVal = from || null;
    const toVal = to || null;

    const [logs] = await pool.execute(
      `SELECT id, service, environment, data, timestamp
       FROM logger_logs
       WHERE (? IS NULL OR service = ?)
         AND (? IS NULL OR timestamp >= ?)
         AND (? IS NULL OR timestamp <= ?)
       ORDER BY timestamp DESC
       LIMIT ? OFFSET ?`,
      [serviceVal, serviceVal, fromVal, fromVal, toVal, toVal, Number(limit), Number(skip)]
    );

    const [[{ total }]] = await pool.execute(
      `SELECT COUNT(*) AS total
       FROM logger_logs
       WHERE (? IS NULL OR service = ?)
         AND (? IS NULL OR timestamp >= ?)
         AND (? IS NULL OR timestamp <= ?)`,
      [serviceVal, serviceVal, fromVal, fromVal, toVal, toVal]
    );

    const mapped = logs.map((row) => ({
      _id: row.id,
      service: row.service,
      environment: row.environment,
      data: typeof row.data === 'string' ? JSON.parse(row.data) : row.data,
      timestamp: row.timestamp,
    }));

    res.json({ total, count: mapped.length, logs: mapped });
  } catch (err) {
    res.status(500).json({ error: 'Failed to fetch logs' });
  }
});

router.get('/errors', authenticate, async (req, res) => {
  try {
    const { service, from, to, limit = 100, skip = 0 } = req.query;

    const serviceVal = service || null;
    const fromVal = from || null;
    const toVal = to || null;

    const [errors] = await pool.execute(
      `SELECT id, service, environment, data, timestamp
       FROM logger_errors
       WHERE (? IS NULL OR service = ?)
         AND (? IS NULL OR timestamp >= ?)
         AND (? IS NULL OR timestamp <= ?)
       ORDER BY timestamp DESC
       LIMIT ? OFFSET ?`,
      [serviceVal, serviceVal, fromVal, fromVal, toVal, toVal, Number(limit), Number(skip)]
    );

    const [[{ total }]] = await pool.execute(
      `SELECT COUNT(*) AS total
       FROM logger_errors
       WHERE (? IS NULL OR service = ?)
         AND (? IS NULL OR timestamp >= ?)
         AND (? IS NULL OR timestamp <= ?)`,
      [serviceVal, serviceVal, fromVal, fromVal, toVal, toVal]
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

router.get('/services', authenticate, async (_req, res) => {
  try {
    const [rows] = await pool.execute(
      `SELECT service, COUNT(*) AS count, MAX(timestamp) AS lastLog
       FROM logger_logs
       GROUP BY service`
    );
    const byService = Object.fromEntries(rows.map((r) => [r.service, r]));
    const services = config.users.map((u) => ({
      service: u.service,
      name: u.name || u.service,
      count: byService[u.service]?.count ?? 0,
      lastLog: byService[u.service]?.lastLog ?? null,
    }));
    services.sort((a, b) => (b.lastLog ?? '') < (a.lastLog ?? '') ? -1 : 1);
    res.json({ services });
  } catch (err) {
    res.status(500).json({ error: 'Failed to fetch services' });
  }
});

module.exports = router;
