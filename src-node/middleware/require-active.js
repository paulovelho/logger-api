const config = require('../../config.json');

function requireActive(req, res, next) {
  const user = config.users.find((u) => u.service === req.service);
  if (!user || user.active === false) {
    return res.status(403).json({ error: 'Forbidden' });
  }
  next();
}

module.exports = requireActive;
