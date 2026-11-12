const fs=require('fs');
const path=require('path');
const ts=require('../r71-mobile-typecheck/node_modules/typescript');
const folder=__dirname;
const names=['NavigatorPairingQrScreen.tsx','InstanceLinkScreen.tsx','CoreStack.tsx',
'AppNavigator.tsx','navigator-pairing-v2.ts','navigator-pairing-v2-message.ts'];
for(const file of names) {
  const source=fs.readFileSync(path.join(folder,file),'utf8');
  const result=ts.transpileModule(source,{
    fileName:file,
    reportDiagnostics:true,
    compilerOptions:{target:ts.ScriptTarget.ES2020,
      module:ts.ModuleKind.CommonJS,jsx:ts.JsxEmit.ReactJSX}
  });
  const errors=result.diagnostics.filter(x=>x.category===ts.DiagnosticCategory.Error);
  if(errors.length) {
    console.error('R72_TS_SYNTAX_FAIL='+file,errors.map(x=>x.messageText));
    process.exit(1);
  }
  console.log('R72_TS_SYNTAX_'+file+'=PASS');
}
const qr=fs.readFileSync(path.join(folder,'NavigatorPairingQrScreen.tsx'),'utf8');
const nav=fs.readFileSync(path.join(folder,'AppNavigator.tsx'),'utf8');
const stacked=fs.readFileSync(path.join(folder,'CoreStack.tsx'),'utf8');
const client=fs.readFileSync(path.join(folder,'navigator-pairing-v2.ts'),'utf8');
const checks={
  'QR_OPAQUE_ONLY':qr.includes('/^[a-f0-9]{64}$/')&&qr.includes('useCodeScanner'),
  'QR_TRUSTED_HOST_ONLY':qr.includes("config('FLEETBASE_HOST'")&&!qr.includes('http://'),
  'NO_CLAIM_IN_SCREEN':!qr.includes('claimAfterAdminApproval('),
  'DEVICE_CODE_VISIBLE':qr.includes('publicKeyFingerprint.slice(0, 12)'),
  'SCREEN_NAVIGATION_WIRED':nav.includes('NavigatorPairingQr,')&&stacked.includes('NavigatorPairingQrScreen'),
  'CLIENT_POST_ONLY':client.includes("method: 'POST'")&&!client.includes("method: 'GET'"),
  'NO_LOGGING_IN_NEW_CLIENT':!qr.includes('console.log')&&!client.includes('console.log')
};
for(const [name,ok] of Object.entries(checks)) {
  console.log('R72_MOBILE_STATIC_'+name+'='+(ok?'PASS':'FAIL'));
}
if(Object.values(checks).some(x=>!x))process.exit(1);
console.log('R72_MOBILE_SYNTAX=6/6;STATIC=7/7');
