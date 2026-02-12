const express = require('express');
const Log = require('../models/Log');
const authenticate = require('../middleware/auth');

const router = express.Router();

router.post('/', authenticate, async (req, res) => {
  try {
    const entry = await Log.create({
      userId: req.userId,
      data: req.body,
    });
    res.status(201).json({ id: entry._id, timestamp: entry.timestamp });
  } catch (err) {
    res.status(500).json({ error: 'Failed to save log' });
  }
});

module.exports = router;
