const mongoose = require('mongoose');

const logSchema = new mongoose.Schema(
  {
    userId: { type: String, required: true, index: true },
    data: { type: mongoose.Schema.Types.Mixed, required: true },
    timestamp: { type: Date, default: Date.now, index: true },
  },
  { versionKey: false }
);

module.exports = mongoose.model('Log', logSchema);
