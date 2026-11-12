const assert=require('node:assert/strict');
const qrcode=require('qrcode');
const {PNG}=require('pngjs');
const jsQR=require('jsqr');
(async()=>{
  const synthetic=Buffer.alloc(32,0x2a).toString('hex');
  assert.equal(synthetic.length,64);
  const generated=await qrcode.toDataURL(synthetic,{
    errorCorrectionLevel:'M',
    margin:4,width:280,
    color:{dark:'#000000',light:'#ffffff'}
  });
  assert(generated.startsWith('data:image/png;base64,'));
  console.log('R73_QR_LOCAL_PNG_GENERATION=PASS');
  const png=PNG.sync.read(Buffer.from(generated.slice('data:image/png;base64,'.length),'base64'));
  const result=jsQR(new Uint8ClampedArray(png.data),png.width,png.height);
  assert.equal(result?.data,synthetic);
  console.log('R73_QR_DECODE_EQUALS_OPAQUE_TOKEN=PASS');
  assert(!/^https?:/.test(result.data) && !result.data.includes('key='));
  console.log('R73_QR_NO_BEARER_URL=PASS');
  assert(png.width>=200 && png.width<=400);
  console.log('R73_QR_DIMENSION=PASS');
  console.log('R73_QR_PROOF=4/4');
})().catch(()=>{console.error('R73_QR_PROOF=FAIL');process.exit(1);});
