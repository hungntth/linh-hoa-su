/**
 * node:test checks for the platform v2 signing helpers (shared test vector from magiccampus plan 08 §1).
 * Run: node --test scripts/test-platform-sign.js
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const {
  CREDIT_PATH,
  REDEEM_PATH,
  parseRedeemed,
  platformHeaders,
  platformSign,
  verifyPlatformSign,
} = require('../src/platform/platformClient');

const VECTOR = '96a48ed4ecdeef74d1c878bc23d573461516c32cffe6c33b1c38fa09e3cfec2b';

test('platformSign matches the shared test vector', () => {
  assert.equal(platformSign('test-secret', '1700000000', 'POST', REDEEM_PATH, '{"code":"abc"}'), VECTOR);
  assert.equal(platformSign('test-secret', '1700000000', 'post', REDEEM_PATH, '{"code":"abc"}'), VECTOR);
});

test('verifyPlatformSign accepts the vector inside the window and rejects tampering', () => {
  const base = { ts: '1700000000', method: 'POST', path: REDEEM_PATH, body: '{"code":"abc"}', sign: VECTOR };
  assert.equal(verifyPlatformSign('test-secret', base, 1700000000), true);
  assert.equal(verifyPlatformSign('test-secret', { ...base, sign: VECTOR.toUpperCase() }, 1700000300), true);
  assert.equal(verifyPlatformSign('test-secret', base, 1700000301), false);
  assert.equal(verifyPlatformSign('test-secret', base, 1699999699), false);
  assert.equal(verifyPlatformSign('other-secret', base, 1700000000), false);
  assert.equal(verifyPlatformSign('', base, 1700000000), false);
  assert.equal(verifyPlatformSign('test-secret', { ...base, path: CREDIT_PATH }, 1700000000), false);
  assert.equal(verifyPlatformSign('test-secret', { ...base, body: '{"code":"abd"}' }, 1700000000), false);
  assert.equal(verifyPlatformSign('test-secret', { ...base, sign: undefined }, 1700000000), false);
  assert.equal(verifyPlatformSign('test-secret', { ...base, sign: 'abc' }, 1700000000), false);
  assert.equal(verifyPlatformSign('test-secret', { ...base, ts: '1700000000.5' }, 1700000000), false);
});

test('platformHeaders signs what verifyPlatformSign checks', () => {
  const body = '{"transferId":"t1","amount":5}';
  const h = platformHeaders({ gameId: 'g', secret: 's' }, 'POST', CREDIT_PATH, body, 1700000000123);
  assert.equal(h['X-Platform-Game'], 'g');
  assert.equal(h['X-Platform-Ts'], '1700000000');
  const ok = verifyPlatformSign('s', { ts: h['X-Platform-Ts'], method: 'POST', path: CREDIT_PATH, body, sign: h['X-Platform-Sign'] }, 1700000001);
  assert.equal(ok, true);
});

test('parseRedeemed narrows the redeem reply', () => {
  assert.deepEqual(parseRedeemed({ accountId: '12', username: 'u', serverId: 1001 }), {
    accountId: '12',
    username: 'u',
    serverId: 1001,
  });
  assert.equal(parseRedeemed({ accountId: 12, username: 'u', serverId: 1001 }), null);
  assert.equal(parseRedeemed({ accountId: '12', username: 'u', serverId: '1001' }), null);
  assert.equal(parseRedeemed(null), null);
});
