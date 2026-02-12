const express = require('express');
const mongoose = require('mongoose');
const dotenv = require('dotenv');
const authRoutes = require('./routes/auth');
const logRoutes = require('./routes/log');
const reportRoutes = require('./routes/report');

dotenv.config();

const app = express();
app.use(express.json());

app.use('/login', authRoutes);
app.use('/log', logRoutes);
app.use('/report', reportRoutes);

app.get('/health', (_req, res) => res.json({ status: 'ok' }));

const PORT = process.env.PORT || 3000;

mongoose
  .connect(process.env.MONGO_URI)
  .then(() => {
    console.log('Connected to MongoDB');
    app.listen(PORT, () => console.log(`Logger API running on port ${PORT}`));
  })
  .catch((err) => {
    console.error('MongoDB connection error:', err);
    process.exit(1);
  });
