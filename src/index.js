const path = require('path');
const express = require('express');
const cors = require('cors');
const dotenv = require('dotenv');
const authRoutes = require('./routes/auth');
const logRoutes = require('./routes/log');
const reportRoutes = require('./routes/report');
const errorRoutes = require('./routes/error');
const errorsRoutes = require('./routes/errors');
const adminRoutes = require('./routes/admin');

dotenv.config();

const app = express();
app.use(cors());
app.use(express.json());
app.use(express.static(path.join(__dirname, '..', 'public')));

app.use('/login', authRoutes);
app.use('/log', logRoutes);
app.use('/report', reportRoutes);
app.use('/error', errorRoutes);
app.use('/errors', errorsRoutes);
app.use('/admin', adminRoutes);

app.get('/admin', (_req, res) =>
  res.sendFile(path.join(__dirname, '..', 'public', 'admin.html'))
);

app.get('/help', (_req, res) => res.redirect('/docs'));

app.get('/docs', (_req, res) =>
  res.sendFile(path.join(__dirname, '..', 'public', 'docs.html'))
);

app.get('/openapi.yaml', (_req, res) =>
  res.sendFile(path.join(__dirname, '..', 'openapi.yaml'))
);

app.get('/health', (_req, res) => res.json({ status: 'ok' }));

const PORT = process.env.PORT || 3000;

const pool = require('./db');

pool.getConnection()
  .then((conn) => {
    conn.release();
    console.log('Connected to MariaDB');
    app.listen(PORT, () => console.log(`Logger API running on port ${PORT}`));
  })
  .catch((err) => {
    console.error('MariaDB connection error:', err);
    process.exit(1);
  });
