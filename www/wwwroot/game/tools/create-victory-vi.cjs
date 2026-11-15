// Preserve the complete Spine atlas and replace only its two victory glyphs.
const fs = require('fs');
const path = require('path');
const dir = path.join(__dirname, '..', 'update/resource/assets/spine/ui/sp_ui_zhandoushengli/lang_cn');
const original = fs.readFileSync(path.join(dir, 'sp_ui_zhandoushengli_88990404.png'));
if (original.readUInt32BE(16) !== 1256 || original.readUInt32BE(20) !== 1256) {
  throw new Error('Unexpected atlas dimensions');
}
const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="1256" height="1256" viewBox="0 0 1256 1256">
<defs>
  <mask id="victory-cut" maskUnits="userSpaceOnUse" x="0" y="0" width="1256" height="1256">
    <rect width="1256" height="1256" fill="white"/>
    <rect x="807" y="2" width="226" height="114" fill="black"/>
  </mask>
  <linearGradient id="gold" x1="0" y1="0" x2="0" y2="1">
    <stop offset="0" stop-color="#fffbd1"/><stop offset="0.6" stop-color="#ffe184"/><stop offset="1" stop-color="#ff9f24"/>
  </linearGradient>
</defs>
<image width="1256" height="1256" mask="url(#victory-cut)" href="data:image/png;base64,${original.toString('base64')}"/>
<g font-family="Georgia,serif" font-weight="bold" font-style="italic" text-anchor="middle" paint-order="stroke fill" fill="url(#gold)" stroke="#b74300" stroke-width="3" stroke-linejoin="round">
  <text x="869" y="70" font-size="30" textLength="65" lengthAdjust="spacingAndGlyphs">CHIẾN</text>
  <text x="982.5" y="70" font-size="30" textLength="65" lengthAdjust="spacingAndGlyphs">THẮNG</text>
</g>
</svg>`;
fs.writeFileSync(path.join(dir, 'sp_ui_zhandoushengli_vi.svg'), svg);
console.log('Vietnamese victory atlas written at 1256 x 1256.');
