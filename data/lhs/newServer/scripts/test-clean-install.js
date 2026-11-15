'use strict';

// Run only against a disposable installation: this creates a player account.
const assert = require('assert/strict');
const { randomBytes } = require('crypto');
const WebSocket = require('ws');
const { Pack, Unpack } = require('../src/protocol/Codec');
const { packRequest, unpackResponse } = require('../src/protocol/ReqCodec');

const base = process.env.SMOKE_BASE_URL || 'http://web';
const accountId = `smoke${randomBytes(5).toString('hex')}`;
const password = `A${randomBytes(10).toString('hex')}9!`;

async function postJson(path, body) {
  const response = await fetch(`${base}${path}`, {
    method: 'POST', headers: { 'content-type': 'application/json' }, body: JSON.stringify(body),
  });
  const bodyJson = await response.json();
  assert.ok(response.ok && bodyJson.success, `${path} failed: ${bodyJson.code || response.status}`);
  return bodyJson.data;
}

async function gameMessage(id, data) {
  const response = await fetch(`${base}/gameMsg.do`, {
    method: 'POST', headers: { 'content-type': 'application/octet-stream' }, body: packRequest(id, data),
  });
  assert.equal(response.status, 200);
  return unpackResponse(Buffer.from(await response.arrayBuffer()));
}

async function websocketLogin(token) {
  const url = new URL('/ws', base);
  url.protocol = url.protocol === 'https:' ? 'wss:' : 'ws:';
  await new Promise((resolve, reject) => {
    const ws = new WebSocket(url);
    const tags = new Set();
    let sentEcho = false;
    let completed = false;
    const timer = setTimeout(() => finish(new Error(`WebSocket login timed out; received: ${[...tags]}`)), 20000);
    function finish(err) {
      if (completed) return;
      completed = true;
      clearTimeout(timer);
      ws.close();
      if (err) reject(err); else resolve();
    }
    ws.on('open', () => ws.send(Buffer.from(Pack(1, { userToken: token, serverId: 1001, batchMsgEnabled: true }, 1))));
    ws.on('error', finish);
    ws.on('close', () => { if (!completed) finish(new Error('WebSocket closed before login completed')); });
    ws.on('message', (raw) => {
      try {
        const bytes = Buffer.from(raw);
        const decoded = Unpack(bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength));
        for (const message of (Array.isArray(decoded) ? decoded : [decoded])) {
          if (!message || !message.tag) continue;
          assert.ok(!message.err, `WebSocket tag ${message.tag} returned an error`);
          if (message.tag === 1) assert.equal(message.data.result, 0, 'game login failed');
          tags.add(message.tag);
        }
        if (tags.has(1) && tags.has(500) && !sentEcho) {
          sentEcho = true;
          ws.send(Buffer.from(Pack(10, { value: 'smoke' }, 2)));
        }
        if (sentEcho && tags.has(10)) finish();
      } catch (err) { finish(err); }
    });
  });
}

(async () => {
  const page = await fetch(`${base}/register.html`);
  assert.equal(page.status, 200, 'registration page is unavailable');
  const detail = await gameMessage(3074, { pageId: 1 });
  assert.ok(detail.servers.some((server) => Number(server.serverId) === 1001 && server.running), 'default game server is missing');
  await postJson('/api/user/register', { accountId, password, confirmPassword: password });
  const login = await postJson('/api/user/login', { accountId, password });
  assert.ok(login.userToken && login.roles.length, 'account has no token or player role');
  await websocketLogin(login.userToken);
  console.log('PASS: server list, registration, login, player initialization and game WebSocket');
})().catch((err) => { console.error(err); process.exitCode = 1; });
