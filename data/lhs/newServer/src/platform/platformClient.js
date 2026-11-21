/**
 * Platform contract helpers (magiccampus docs/plans 2026-11-21_08 §1 and 2026-11-21_09 §2):
 * v2 HMAC signing/verification and the server-to-server ticket redeem call. Reads PLATFORM_* env only.
 */

const crypto = require('crypto');

/** Logical path of the platform's ticket redeem endpoint (signed as-is, whatever prefix PLATFORM_URL has). */
const REDEEM_PATH = '/internal/tickets/redeem';
/** Logical path of this game's credit endpoint (signed as-is, whatever the real creditUrl is). */
const CREDIT_PATH = '/internal/platform/credit';
/** Allowed clock skew between the platform and this game, in seconds. */
const SIGN_SKEW_S = 300;

function getPlatformConfig() {
  return {
    url: String(process.env.PLATFORM_URL || '').trim().replace(/\/+$/, ''),
    gameId: String(process.env.PLATFORM_GAME_ID || '').trim(),
    secret: String(process.env.PLATFORM_SECRET || ''),
    timeoutMs: Math.max(1000, Number(process.env.PLATFORM_TIMEOUT_MS || 5000) || 5000),
  };
}

function isPlatformEnabled(cfg = getPlatformConfig()) {
  return Boolean(cfg.url && cfg.gameId && cfg.secret);
}

/** hex(HMAC-SHA256(secret, `${ts}\n${METHOD}\n${path}\n${body}`)); `body` is the exact raw string sent. */
function platformSign(secret, ts, method, path, body) {
  return crypto
    .createHmac('sha256', secret)
    .update(`${ts}\n${String(method).toUpperCase()}\n${path}\n${body}`)
    .digest('hex');
}

/** Checks ts/sign shape, the ±300 s window, then compares in constant time. */
function verifyPlatformSign(secret, { ts, method, path, body, sign }, nowS = Math.floor(Date.now() / 1000)) {
  if (!secret || typeof ts !== 'string' || typeof sign !== 'string' || typeof body !== 'string') return false;
  if (!/^\d{1,12}$/.test(ts) || !/^[0-9a-f]{64}$/i.test(sign)) return false;
  if (Math.abs(nowS - Number(ts)) > SIGN_SKEW_S) return false;
  const expected = Buffer.from(platformSign(secret, ts, method, path, body), 'hex');
  return crypto.timingSafeEqual(expected, Buffer.from(sign, 'hex'));
}

function platformHeaders(cfg, method, path, body, nowMs = Date.now()) {
  const ts = String(Math.floor(nowMs / 1000));
  return {
    'Content-Type': 'application/json',
    'X-Platform-Game': cfg.gameId,
    'X-Platform-Ts': ts,
    'X-Platform-Sign': platformSign(cfg.secret, ts, method, path, body),
  };
}

class PlatformError extends Error {
  constructor(status, code) {
    super(`platform request failed: ${code} (HTTP ${status})`);
    this.status = status;
    this.code = code;
  }
}

function parseRedeemed(value) {
  if (!value || typeof value !== 'object') return null;
  const { accountId, username, serverId } = value;
  if (typeof accountId !== 'string' || !/^\d{1,20}$/.test(accountId)) return null;
  if (typeof username !== 'string' || !Number.isInteger(serverId)) return null;
  return { accountId, username, serverId };
}

/**
 * Trades a one-time launch ticket for the platform account {accountId, username, serverId}.
 * Throws PlatformError (message never contains the ticket or the secret).
 */
async function redeemTicket(code, cfg = getPlatformConfig()) {
  if (!isPlatformEnabled(cfg)) throw new PlatformError(0, 'disabled');
  const body = JSON.stringify({ code: String(code) });
  let res;
  try {
    res = await fetch(`${cfg.url}${REDEEM_PATH}`, {
      method: 'POST',
      headers: platformHeaders(cfg, 'POST', REDEEM_PATH, body),
      body,
      signal: AbortSignal.timeout(cfg.timeoutMs),
    });
  } catch (_err) {
    throw new PlatformError(0, 'unreachable');
  }
  const json = await res.json().catch(() => null);
  if (res.status !== 200) {
    const errCode = json && typeof json === 'object' && typeof json.error === 'string' ? json.error : 'error';
    throw new PlatformError(res.status, errCode);
  }
  const parsed = parseRedeemed(json);
  if (!parsed) throw new PlatformError(res.status, 'bad_response');
  return parsed;
}

module.exports = {
  REDEEM_PATH,
  CREDIT_PATH,
  SIGN_SKEW_S,
  PlatformError,
  getPlatformConfig,
  isPlatformEnabled,
  platformSign,
  verifyPlatformSign,
  platformHeaders,
  parseRedeemed,
  redeemTicket,
};
