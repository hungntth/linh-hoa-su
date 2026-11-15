const { Schema, model } = require('mongoose');

const DEFAULT_SERVER_ROWS = Object.freeze([
  { pageId: 1, pageName: 'Kiếm Chỉ Thương Khung', serverId: 1001, serverName: 'Thương Khung', isNew: false, isRecommend: true },
]);

function getDefaultRechargeArea() {
  const area = Number(process.env.DEFAULT_RECHARGE_AREA || process.env.RECHARGE_AREA || 1);
  return [1, 2, 3].includes(area) ? area : 1;
}

function buildDefaultServerRows(now = Date.now()) {
  return DEFAULT_SERVER_ROWS.map((row) => {
    const isNew = !!row.isNew;
    return {
      ...row,
      status: 1,
      running: true,
      openTime: now - 86400000 * (isNew ? 3 : 30),
      rechargeArea: getDefaultRechargeArea(),
    };
  });
}

const gameServerSchema = new Schema(
  {
    serverId: { type: Number, required: true, unique: true, index: true },
    pageId: { type: Number, required: true, index: true },
    pageName: { type: String, required: true },
    serverName: { type: String, required: true },
    isNew: { type: Boolean, default: false },
    isRecommend: { type: Boolean, default: false },
    status: { type: Number, default: 1 },
    running: { type: Boolean, default: true },
    openTime: { type: Number, default: 0 },
    rechargeArea: { type: Number, default: 1 },
  },
  {
    timestamps: true,
    minimize: false,
  }
);

gameServerSchema.index({ pageId: 1, serverId: 1 });

gameServerSchema.statics.getDefaultRows = function getDefaultRows() {
  return buildDefaultServerRows();
};

gameServerSchema.statics.initializeDefaults = async function initializeDefaults() {
  const count = await this.countDocuments({});
  if (count > 0) return { inserted: 0, skipped: true };

  const rows = buildDefaultServerRows();
  await this.insertMany(rows, { ordered: false });
  return { inserted: rows.length, skipped: false };
};

module.exports = model('GameServer', gameServerSchema);
