/**
 * Game accounts linked to platform accounts (Account.platformUid), shared by SSO login and platform credit, plus
 * the one-time SSO login code that /api/user/sso-login hands to the client and SDKAuth (tag 2820) consumes.
 */

const crypto = require('crypto');
const Account = require('../db/models/Account');
const accountService = require('../services/AccountService');
const redis = require('../db/redis');
const logger = require('../logger');

const SSO_CODE_PREFIX = 'sso:';
const SSO_CODE_TTL_S = 60;

function getServerId() {
  return Math.floor(Number(process.env.SERVER_ID || process.env.DEFAULT_SERVER_ID || 1001)) || 1001;
}

function roleOnServer(account, serverId) {
  const roles = Array.isArray(account && account.roles) ? account.roles : [];
  const preferredId = account && account.activeRoleByServer && account.activeRoleByServer[String(serverId)];
  const sameServer = roles.filter((r) => Number(r && r.serverId) === serverId && r.userId);
  return sameServer.find((r) => String(r.roleId) === String(preferredId || '')) || sameServer[0] || null;
}

async function createLinkedAccount(platformUid) {
  // Unusable password: not a bcrypt hash and never equal to a sha256 hex, so no password login can match it.
  const passwordHash = `!platform:${crypto.randomBytes(24).toString('hex')}`;
  const base = `pf${platformUid}`;
  for (let i = 0; i < 5; i += 1) {
    const accountId = i === 0 ? base : `${base}_${crypto.randomBytes(2).toString('hex')}`;
    try {
      await Account.create({
        accountId,
        passwordHash,
        platformUid,
        roles: [],
        activeRoleByServer: {},
        lastLoginTime: 0,
        status: 1,
      });
      logger.info(`[Platform] created account ${accountId} for platform uid ${platformUid}`);
      return;
    } catch (err) {
      if (!err || err.code !== 11000) throw err;
      // Either another request linked this platformUid first (done) or the accountId is taken (try a suffix).
      if (await Account.exists({ platformUid })) return;
    }
  }
  throw new Error('PLATFORM_ACCOUNT_CREATE_FAILED');
}

const pending = new Map();

/**
 * Finds or creates the game account of a platform account and makes sure it has a role on this server (created
 * like /api/user/register does). Returns { account, role } (role may be null if role creation failed).
 * Serialized per platformUid inside this process so concurrent SSO/credit calls do not create two roles.
 */
function ensurePlatformAccount(platformUid, username = '') {
  const key = String(platformUid);
  const prev = pending.get(key) || Promise.resolve();
  const run = prev.catch(() => undefined).then(() => ensureNow(key, username));
  pending.set(key, run);
  run.finally(() => {
    if (pending.get(key) === run) pending.delete(key);
  }).catch(() => undefined);
  return run;
}

async function ensureNow(platformUid, username) {
  let account = await Account.findOne({ platformUid });
  if (!account) {
    await createLinkedAccount(platformUid);
    account = await Account.findOne({ platformUid });
    if (!account) throw new Error('PLATFORM_ACCOUNT_MISSING');
  }
  const serverId = getServerId();
  let role = roleOnServer(account, serverId);
  if (!role) {
    const roleName = String(username || '').replace(/\s+/g, '').slice(0, 24) || account.accountId;
    await accountService.createRole(account.accountId, { preAuthenticated: true, serverId, roleName });
    account = await Account.findOne({ platformUid });
    role = roleOnServer(account, serverId);
  }
  return { account, role };
}

/** Stores a one-time login code for accountId; returns the client-facing code ('sso:<random>'). */
async function createSsoCode(accountId) {
  const client = redis.getClient();
  if (!client) throw new Error('REDIS_UNAVAILABLE');
  const random = crypto.randomBytes(24).toString('base64url');
  await client.set(`sso:code:${random}`, String(accountId), 'EX', SSO_CODE_TTL_S);
  return `${SSO_CODE_PREFIX}${random}`;
}

function isSsoCode(token) {
  return typeof token === 'string' && token.startsWith(SSO_CODE_PREFIX) && token.length > SSO_CODE_PREFIX.length;
}

/** Atomically consumes a code (GETDEL); true only if it existed and was issued for accountId. */
async function consumeSsoCode(token, accountId) {
  if (!isSsoCode(token)) return false;
  const client = redis.getClient();
  if (!client) return false;
  const random = token.slice(SSO_CODE_PREFIX.length);
  if (!/^[A-Za-z0-9_-]{16,64}$/.test(random)) return false;
  const owner = await client.getdel(`sso:code:${random}`);
  return typeof owner === 'string' && owner !== '' && owner === String(accountId || '').trim().toLowerCase();
}

module.exports = {
  getServerId,
  roleOnServer,
  ensurePlatformAccount,
  createSsoCode,
  isSsoCode,
  consumeSsoCode,
};
