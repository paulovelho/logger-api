const express = require('express');
const jwt = require('jsonwebtoken');
const config = require('../../config.json');

const router = express.Router();

router.post('/', (req, res) => {
  const { service, secret } = req.body;

  const user = config.users.find(
    (u) => u.service === service && u.secret === secret
  );

  if (!user) {
    return res.status(401).json({ error: 'Invalid credentials' });
  }

  const token = jwt.sign({ service: user.service }, process.env.JWT_SECRET);

  res.json({ token });
});

module.exports = router;
