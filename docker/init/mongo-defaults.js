// Initialize only a fresh game database. Never overwrite restored server data.
const gameDb = db.getSiblingDB('lhs_s1001');
const servers = gameDb.getCollection('gameservers');
if (servers.countDocuments({}) === 0) {
  const now = new Date();
  servers.insertOne({
    serverId: 1001,
    pageId: 1,
    pageName: 'Kiếm Chỉ Thương Khung',
    serverName: 'Thương Khung',
    isNew: true,
    isRecommend: true,
    status: 1,
    running: true,
    openTime: now.getTime() - 30 * 86400000,
    rechargeArea: 1,
    createdAt: now,
    updatedAt: now,
  });
  print('Initialized the default local game server (1001).');
} else {
  print('Existing game servers found; leaving them unchanged.');
}
