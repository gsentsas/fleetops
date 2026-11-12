const assert=require('node:assert/strict');
const fs=require('node:fs');
const root='/opt/gst-rc1-staging/s98-r62-fleetops-v2';
const babel=require('@babel/parser');
const {preprocess}=require('@glimmer/syntax');
const app=fs.readFileSync(root+'/addon/components/navigator-pairing-approvals.js','utf8');
const html=fs.readFileSync(root+'/addon/components/navigator-pairing-approvals.hbs','utf8');
const pkg=JSON.parse(fs.readFileSync(root+'/package.json','utf8'));
babel.parse(app,{sourceType:'module',plugins:['decorators-legacy','classProperties']});
preprocess(html);
console.log('R73_EMBER_QR_JS_AND_HBS_SYNTAX=2/2');
const guards={
  'QR_LIBRARY_PINNED': pkg.dependencies.qrcode==='1.5.4',
  'QR_JS_LOCAL_ONLY': app.includes("import QRCode from 'qrcode'") && app.includes('QRCode.toDataURL(token'),
  'QR_OPAQUE_HEX_ONLY': app.includes("/^[a-f0-9]{64}$/"),
  'QR_TTL_MAX_300': app.includes('ttl > 300') && app.includes('setTimeout(() => this.clearQr(), ttl * 1000)'),
  'QR_CLEARED_ON_DESTROY': app.includes('willDestroy()') && app.includes('this.clearQr();'),
  'QR_DATA_URI_ONLY': app.includes("image.startsWith('data:image/png;base64,')"),
  'ADMIN_ONLY_ISSUE': app.includes('if (!this.canApprove) return;') && app.includes("this.fetch.post('fleet-ops/navigator/pairings', {})"),
  'QR_IS_LOCAL_IMAGE':html.includes('src={{this.qrImage}}') && !html.includes('https://') && !html.includes('?key='),
  'QR_NO_RAW_TOKEN_IN_UI':!html.includes('pairingSecret') && !html.includes('QR_TOKEN'),
  'QR_NO_TOKEN_LOGS':!app.includes('console.log') && !app.includes('console.error'),
  'QR_NOT_AUTO_APPROVED':app.includes("this.confirmation === this.selected.deviceConfirmationCode")
};
for(const [key,valid] of Object.entries(guards)){
  console.log('R73_'+key+'='+(valid?'PASS':'FAIL'));
  assert(valid,key);
}
console.log('R73_QR_CONSOLE_SOURCE_GATES=11/11');
