// Update only the five text entries for the Tiên Vực Tranh Phong help pages.
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');
const { isDeepStrictEqual } = require('util');
const { BinJsonUtil, ByteArray } = require('../../../../data/lhs/newServer/src/protocol/BinJsonUtil');

ByteArray.prototype._validateBuffer = function (size) {
  if (this.data.byteLength >= size) return;
  const bytes = new Uint8Array(Math.max(size, this.data.byteLength * 2, 10240));
  bytes.set(this._bytes);
  this._bytes = bytes;
  this.data = new DataView(bytes.buffer);
};

const file = path.join(__dirname, '../update/resource/assets/excel/lang_cn/config_lang_c2fa8585.bin');
const source = fs.readFileSync(file);
const input = new ByteArray(source);
const name = input.readUTFBytes(input.readUnsignedByte());
const lengthOffset = input.position;
const length = input.readUnsignedInt();
const dataOffset = input.position;
if (name !== 'LanguageConfig' || dataOffset + length > source.length) throw new Error('Unexpected language file');
const data = BinJsonUtil.readFullJson(new ByteArray(zlib.inflateSync(source.subarray(dataOffset, dataOffset + length))));
const updates = {
  30010596: 'Thiên đạo biến động, các tiên vực giao nhau. Trong thời gian hoạt động, tiên hữu có thể đến tiên vực khác viễn chinh hoặc trấn thủ tiên vực của mình để ngăn đối phương xâm nhập.',
  30010597: 'Giai đoạn du kích: Tấn công tiên vực khác, thu thập tài nguyên, đánh bại tiên hữu ngoại vực và xâm nhập hạt nhân tiên vực để nhận điểm.',
  30010598: 'Giai đoạn chiến đấu: Xâm nhập bản nguyên của tiên vực khác, chiếm và giữ tinh hoa bản nguyên để tăng điểm tích lũy cho phe mình.',
  30010599: 'Trong bản đồ, số người cùng phe trực tuyến và sự bài xích từ tiên vực khác có thể tạo hiệu ứng tăng hoặc giảm sức mạnh. Dùng vật phẩm để nhận thêm các hiệu ứng.',
  30010600: 'Tích lũy điểm và tinh linh bản nguyên để vươn lên dẫn đầu bảng xếp hạng, lưu danh trong tiên vực.',
};
for (const [id, value] of Object.entries(updates)) {
  if (!data[id] || typeof data[id].cn !== 'string') throw new Error(`Missing language entry ${id}`);
  data[id].cn = value;
}
const encoded = new ByteArray();
BinJsonUtil.json2bin(data, encoded);
if (!isDeepStrictEqual(BinJsonUtil.readFullJson(new ByteArray(encoded.buffer)), data)) throw new Error('Language round-trip failed');
const compressed = zlib.deflateSync(Buffer.from(encoded.buffer));
const header = Buffer.from(source.subarray(0, dataOffset));
header.writeUInt32BE(compressed.length, lengthOffset);
fs.writeFileSync(file, Buffer.concat([header, compressed, source.subarray(dataOffset + length)]));
console.log('Updated and verified five language entries.');
