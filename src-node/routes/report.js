const express = require('express');
const Log = require('../models/Log');
const authenticate = require('../middleware/auth');

const router = express.Router();

router.get('/', authenticate, async (req, res) => {
  try {
    const { from, to, limit = 100, skip = 0 } = req.query;

    const filter = { userId: req.userId };

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

module.exports = router;
