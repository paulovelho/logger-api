const express = require('express');
const Log = require('../models/Log');

const router = express.Router();

// All logs across every service, with optional filters.
// No auth — admin access is open for now.
router.get('/logs', async (req, res) => {
  try {
    const { userId, from, to, limit = 100, skip = 0 } = req.query;

    const filter = {};

    if (userId) filter.userId = userId;

    if (from || to) {
      filter.timestamp = {};
      if (from) filter.timestamp.$gte = new Date(from);
      if (to) filter.timestamp.$lte = new Date(to);
    }

    const logs = await Log.find(filter)
      .sort({ timestamp: -1 })
      .skip(Number(skip))
      .limit(Number(limit))
      .lean();

    const total = await Log.countDocuments(filter);

    res.json({ total, count: logs.length, logs });
  } catch (err) {
    res.status(500).json({ error: 'Failed to fetch logs' });
  }
});

// Per-service summary: log count and latest entry for each userId.
router.get('/services', async (_req, res) => {
  try {
    const services = await Log.aggregate([
      {
        $group: {
          _id: '$userId',
          count: { $sum: 1 },
          lastLog: { $max: '$timestamp' },
        },
      },
      { $sort: { lastLog: -1 } },
      { $project: { _id: 0, userId: '$_id', count: 1, lastLog: 1 } },
    ]);

    res.json({ services });
  } catch (err) {
    res.status(500).json({ error: 'Failed to fetch services' });
  }
});

module.exports = router;
