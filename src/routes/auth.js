const express = require('express');
const jwt = require('jsonwebtoken');
const config = require('../../config.json');

const router = express.Router();

router.post('/', (req, res) => {
  const { userId, secret } = req.body;

  const user = config.users.find(
    (u) => u.userId === userId && u.secret === secret
  );

  if (!user) {
    return res.status(401).json({ error: 'Invalid credentials' });
  }

  const token = jwt.sign({ userId: user.userId }, process.env.JWT_SECRET);

  res.json({ token });
});

module.exports = router;
