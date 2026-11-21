/**
 * POST /internal/platform/credit: the platform's signed Xu -> Kim Cương transfer (contract: magiccampus
 * docs/plans/2026-11-21_09_PLATFORM_PHASE3_WALLET.md §2). Replies: 200 applied|duplicate, 4xx {retryable:false} only
 * when nothing was applied, 5xx otherwise (the platform retries with the same transferId).
 */

const PlatformCredit = require('../db/models/PlatformCredit');
const Player = require('../db/models/Player');
const playerService = require('../services/PlayerService');
const { syncCurrencyItems } = require('../services/CurrencySync');
const { buildItemSyncPayload } = require('../services/PlayerGrantService');
const logger = require('../logger');
const { CREDIT_PATH, getPlatformConfig, verifyPlatformSign } = require('./platformClient');
const { ensurePlatformAccount, getServerId } = require('./platformAccount');

const MAX_CREDIT_AMOUNT = 10000000;

/** The typed request body, or null when any field is missing or out of range. */
function parseCreditBody(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return null;
  const { transferId, accountId, username, serverId, amount } = value;
  if (typeof transferId !== 'string' || !/^[A-Za-z0-9_-]{1,64}$/.test(transferId)) return null;
  if (typeof accountId !== 'string' || !/^\d{1,20}$/.test(accountId)) return null;
  if (typeof username !== 'string' || username.length > 64) return null;
  if (!Number.isInteger(serverId)) return null;
  if (!Number.isInteger(amount) || amount < 1 || amount > MAX_CREDIT_AMOUNT) return null;
  return { transferId, accountId, username, serverId, amount };
}

/** The live WS session of userId: { session } when its player is loaded, { loading: true } mid-login, else null. */
function findLiveSession(wsServer, userId) {
  if (!wsServer || !wsServer.clients) return null;
  let loading = false;
  for (const [, item] of wsServer.clients) {
    const session = item && item.session;
    if (!session || session.userId !== userId || session._closing) continue;
    if (session.player) return { session };
    loading = true;
  }
  return loading ? { loading: true } : null;
}

/**
 * Credits the online player's in-memory snapshot in one synchronous step (the WS session saves full snapshots, so
 * a DB $inc would be overwritten), then persists and pushes the item sync (tag 1202).
 */
async function creditOnline(wsServer, session, userId, amount) {
  const player = session.player;
  if (!player.currencies || typeof player.currencies !== 'object') player.currencies = {};
  player.currencies.diamond = Math.max(0, Number(player.currencies.diamond) || 0) + amount;
  syncCurrencyItems(player);
  const saving = playerService.savePlayer(userId, player); // snapshot is taken synchronously
  try {
    wsServer.pushToUser(userId, 1202, buildItemSyncPayload(player, [1]));
  } catch (err) {
    logger.warn(`[PlatformCredit] push 1202 failed user=${userId}: ${err.message}`);
  }
  try {
    await saving;
  } catch (err) {
    // The session already holds the credit and will persist it on its next save; do not report a failure.
    logger.warn(`[PlatformCredit] save after online credit failed user=${userId}: ${err.message}`);
  }
}

async function claim(row) {
  try {
    await PlatformCredit.create({ ...row, status: 'pending' });
    return 'claimed';
  } catch (err) {
    if (!err || err.code !== 11000) throw err;
    const existing = await PlatformCredit.findById(row._id, { status: 1 }).lean();
    return existing && existing.status === 'done' ? 'duplicate' : 'pending';
  }
}

/** Applies a verified, parsed credit. Returns { http, body }. Throws on unexpected errors (caller replies 500). */
async function applyCredit(req, wsServer) {
  if (req.serverId !== getServerId()) {
    return { http: 400, body: { error: 'wrong_server', retryable: false } };
  }
  const { account, role } = await ensurePlatformAccount(req.accountId, req.username);
  if (!role || !role.userId) return { http: 400, body: { error: 'no_character', retryable: false } };
  const userId = String(role.userId);
  if (!(await Player.exists({ userId }))) return { http: 400, body: { error: 'no_character', retryable: false } };

  const done = await PlatformCredit.findById(req.transferId, { status: 1 }).lean();
  if (done && done.status === 'done') return { http: 200, body: { status: 'duplicate' } };
  if (findLiveSession(wsServer, userId)?.loading) return { http: 503, body: { error: 'player_loading' } };

  const claimed = await claim({
    _id: req.transferId,
    platformUid: req.accountId,
    accountId: account.accountId,
    userId,
    amount: req.amount,
  });
  if (claimed === 'duplicate') return { http: 200, body: { status: 'duplicate' } };
  if (claimed === 'pending') return { http: 500, body: { error: 'in_progress' } };

  // Re-check after the await: a login that started meanwhile must not race a DB $inc.
  const live = findLiveSession(wsServer, userId);
  if (live && live.loading) {
    await PlatformCredit.deleteOne({ _id: req.transferId, status: 'pending' });
    return { http: 503, body: { error: 'player_loading' } };
  }
  let mode;
  if (live && live.session) {
    mode = 'online';
    await creditOnline(wsServer, live.session, userId, req.amount);
  } else {
    mode = 'offline';
    // items.1 mirrors currencies.diamond (CurrencySync); keep both in step.
    const res = await Player.updateOne({ userId }, { $inc: { 'currencies.diamond': req.amount, 'items.1': req.amount } });
    if (!res || res.matchedCount !== 1) {
      await PlatformCredit.deleteOne({ _id: req.transferId, status: 'pending' });
      return { http: 400, body: { error: 'no_character', retryable: false } };
    }
  }
  await PlatformCredit.updateOne({ _id: req.transferId }, { $set: { status: 'done', mode, doneAt: new Date() } });
  logger.info(
    `[PlatformCredit] applied transfer=${req.transferId} uid=${req.accountId} user=${userId} diamond+${req.amount} ${mode}`
  );
  return { http: 200, body: { status: 'applied' } };
}

/** Express handler; `req.body` is the raw Buffer (express.raw on this route). */
async function handleCreditRequest(req, res, wsServer) {
  const cfg = getPlatformConfig();
  if (!cfg.gameId || !cfg.secret) return res.status(404).json({ error: 'disabled', retryable: false });
  const raw = Buffer.isBuffer(req.body) ? req.body.toString('utf8') : undefined;
  const signed = raw !== undefined
    && req.get('x-platform-game') === cfg.gameId
    && verifyPlatformSign(cfg.secret, {
      ts: req.get('x-platform-ts'),
      method: 'POST',
      path: CREDIT_PATH,
      body: raw,
      sign: req.get('x-platform-sign'),
    });
  if (!signed) return res.status(401).json({ error: 'bad_signature', retryable: false });
  let parsed = null;
  try {
    parsed = parseCreditBody(JSON.parse(raw));
  } catch (_err) {
    parsed = null;
  }
  if (!parsed) return res.status(400).json({ error: 'bad_request', retryable: false });
  try {
    const result = await applyCredit(parsed, wsServer);
    return res.status(result.http).json(result.body);
  } catch (err) {
    logger.error(`[PlatformCredit] transfer=${parsed.transferId} failed: ${err && err.message}`);
    return res.status(500).json({ error: 'internal' });
  }
}

module.exports = {
  MAX_CREDIT_AMOUNT,
  parseCreditBody,
  findLiveSession,
  applyCredit,
  handleCreditRequest,
};
