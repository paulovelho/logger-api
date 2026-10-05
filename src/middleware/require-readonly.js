function requireReadonly(req, res, next) {
  if (!req.readonly) {
    return res.status(403).json({ error: 'Forbidden' });
  }
  next();
}

module.exports = requireReadonly;
