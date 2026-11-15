// Update the packed Egret skins while preserving every unrelated skin entry.
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');
const { isDeepStrictEqual } = require('util');
const { BinJsonUtil, ByteArray } = require('../../../../data/lhs/newServer/src/protocol/BinJsonUtil');

// The bundled writer otherwise grows its temporary arrays one byte at a time.
ByteArray.prototype._validateBuffer = function (size) {
  if (this.data.byteLength >= size) return;
  const bytes = new Uint8Array(Math.max(size, this.data.byteLength * 2, 10240));
  bytes.set(this._bytes);
  this._bytes = bytes;
  this.data = new DataView(bytes.buffer);
};

const root = path.join(__dirname, '..', 'update', 'resource', 'assets');
const files = [
  { file: path.join(root, 'cfg', 'gameEui_18e11b8e.bin'), patch: patchGame },
  { file: path.join(root, 'game_start', 'gameEui.start_7594ef80.bin'), patch: patchStart },
];

function patchGame(data) {
  const input = data.LoginInputSkin;
  if (!input || !input._Group1 || !input.nameLabel) throw new Error('LoginInputSkin is missing');
  input.nameLabel.size = 24;
  input._Group1.left = 145;
  input._Group1.width = 333;
  input.input.verticalAlign = 'middle';

  for (const name of ['LoginAnimUISkin', 'LoginAnimUINewSkin']) {
    const skin = data[name];
    if (!skin || !skin.logo || !skin.logoImage || !skin.adviceGroup) throw new Error(`${name} is missing`);
    skin.adviceGroup.visible = false;
    skin.adviceGroup.alpha = 0;
    skin.adviceGroup.touchChildren = false;
    skin.verLabel.visible = false;
    skin.verLabel.alpha = 0;
    skin.logoImage.alpha = 0;
    if (!skin.logo.$eleC.includes('vietnameseLogo')) skin.logo.$eleC.push('vietnameseLogo');
    skin.vietnameseLogo = {
      '$t': '$eL', text: 'Linh Hoạ Sư', fontFamily: 'Georgia', size: 68,
      bold: true, italic: true, textColor: 3286840, stroke: 1, strokeColor: 15589334,
      textAlign: 'center', horizontalCenter: 0, y: 8, width: 580,
      touchEnabled: false,
    };
  }
}

function patchStart(data) {
  const skin = data.StartViewSkin;
  if (!skin || !skin.adviceGroup || !skin.verLabel) throw new Error('StartViewSkin is missing');
  skin.adviceGroup.visible = false;
  skin.adviceGroup.alpha = 0;
  skin.adviceGroup.touchChildren = false;
  skin.verLabel.visible = false;
  skin.verLabel.alpha = 0;
}

const updates = files.map(({ file, patch }) => {
  const data = BinJsonUtil.readFullJson(new ByteArray(zlib.inflateSync(fs.readFileSync(file))));
  patch(data);
  const output = new ByteArray();
  BinJsonUtil.json2bin(data, output);
  const encoded = Buffer.from(output.buffer);
  const roundTrip = BinJsonUtil.readFullJson(new ByteArray(encoded));
  if (!isDeepStrictEqual(roundTrip, data)) throw new Error(`Round-trip mismatch: ${file}`);
  return { file, bytes: zlib.deflateSync(encoded) };
});
for (const { file, bytes } of updates) fs.writeFileSync(file, bytes);
console.log('Updated and verified both login skin files.');
