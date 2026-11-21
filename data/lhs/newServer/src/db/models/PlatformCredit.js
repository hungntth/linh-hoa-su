/**
 * PlatformCredit: one row per platform Xu -> Kim Cương transfer (_id = transferId), claimed before the credit is
 * applied so each transferId lands at most once. status: 'pending' while applying, 'done' once credited.
 */

const { Schema, model } = require('mongoose');

const platformCreditSchema = new Schema(
  {
    _id: { type: String, required: true },
    platformUid: { type: String, required: true, index: true },
    accountId: { type: String, required: true },
    userId: { type: String, required: true, index: true },
    amount: { type: Number, required: true },
    status: { type: String, required: true, default: 'pending' },
    mode: { type: String, default: '' }, // 'online' (session mutated) or 'offline' ($inc)
    doneAt: { type: Date, default: null },
  },
  { timestamps: true, collection: 'platformcredits' }
);

module.exports = model('PlatformCredit', platformCreditSchema);
