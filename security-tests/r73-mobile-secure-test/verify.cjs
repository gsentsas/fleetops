const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const ts=require('../r71-mobile-typecheck/node_modules/typescript');

let entry=null;
let lastOptions=null;
let failSet=false;
let failClear=false;
const mock={
  ACCESSIBLE:{WHEN_UNLOCKED_THIS_DEVICE_ONLY:'WHEN_UNLOCKED_THIS_DEVICE_ONLY'},
  SECURITY_LEVEL:{SECURE_HARDWARE:'SECURE_HARDWARE'},
  async setGenericPassword(user,password,options){
    lastOptions=options;
    if(failSet)return false;
    entry={username:user,password,service:options.service};
    return {service:options.service,storage:'SYNTHETIC_SECURE'};
  },
  async getGenericPassword({service}){
    return entry && entry.service===service?entry:false;
  },
  async resetGenericPassword({service}){
    if(failClear)return false;
    if(entry?.service===service)entry=null;
    return true;
  }
};

const code=fs.readFileSync(__dirname+'/navigator-v2-secure-credentials.ts','utf8');
const output=ts.transpileModule(code,{
  fileName:'navigator-v2-secure-credentials.ts',
  compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2020}
}).outputText;
const mod={exports:{}};
vm.runInNewContext(output,{
  module:mod,exports:mod.exports,URL,
  require(name){
    assert.equal(name,'react-native-keychain');
    return mock;
  }
},{filename:'navigator-v2-secure-credentials.js'});
const {saveNavigatorV2Credential,loadNavigatorV2Credential,clearNavigatorV2Credential}=mod.exports;
let count=0;
function pass(label){count++;console.log('R73_SECURE_'+label+'=PASS');}
(async()=>{
  const trusted='https://api.synthetic.invalid/';
  const socket='socket.synthetic.invalid';
  const credential={
    credential:'SYNTHETIC_TEST_NOT_A_REAL_CREDENTIAL_123456',
    host:trusted,
    socketcluster_host:socket,
    socketcluster_port:8000,
    socketcluster_secure:true
  };
  assert.equal(await loadNavigatorV2Credential(trusted,socket),null);pass('EMPTY_IS_NULL');
  await saveNavigatorV2Credential(credential,trusted,socket);
  assert.equal(lastOptions.securityLevel,'SECURE_HARDWARE');
  assert.equal(lastOptions.accessible,'WHEN_UNLOCKED_THIS_DEVICE_ONLY');
  assert.equal(lastOptions.service,'io.fleetbase.navigator.pairing.v2.credential');
  pass('HARDWARE_ONLY_SERVICE');
  const loaded=await loadNavigatorV2Credential(trusted,socket);
  assert.equal(loaded.credential,credential.credential);
  assert.equal(loaded.host,new URL(trusted).origin);
  pass('SAVE_AND_RESTORE');
  await assert.rejects(loadNavigatorV2Credential('https://wrong.synthetic.invalid/',socket));
  pass('WRONG_TRUSTED_HOST_DENIED');
  await assert.rejects(loadNavigatorV2Credential(trusted,'other.synthetic.invalid'));
  pass('WRONG_WSS_HOST_DENIED');
  await assert.rejects(saveNavigatorV2Credential({...credential,host:'http://api.synthetic.invalid/'},trusted,socket));
  pass('INSECURE_HOST_DENIED');
  await assert.rejects(saveNavigatorV2Credential({...credential,socketcluster_secure:false},trusted,socket));
  pass('INSECURE_WEBSOCKET_DENIED');
  await assert.rejects(saveNavigatorV2Credential({...credential,credential:'short'},trusted,socket));
  pass('SHORT_KEY_DENIED');
  await assert.rejects(saveNavigatorV2Credential({...credential,credential:'bad\nheader'},trusted,socket));
  pass('CONTROL_CHAR_DENIED');
  failSet=true;
  await assert.rejects(saveNavigatorV2Credential(credential,trusted,socket));
  failSet=false;
  pass('HARDWARE_STORE_FAILURE_DENIED');
  failClear=true;
  await assert.rejects(clearNavigatorV2Credential());
  failClear=false;
  pass('FAILED_CLEAR_DETECTED');
  await clearNavigatorV2Credential();
  assert.equal(await loadNavigatorV2Credential(trusted,socket),null);
  pass('CLEAR_AND_NO_RESIDUAL_SECRET');
  assert(!/from ['"]\.\.\/hooks\/use-storage/.test(code));
  assert(!/console\.(log|warn|error)\s*\(/.test(code));
  pass('NO_MMKV_OR_LOGS');
  console.log('R73_SECURE_KEYCHAIN_CONTRACT='+count+'/'+count);
})().catch((e)=>{
  console.error('R73_SECURE_KEYCHAIN_CONTRACT=FAIL',e.message);
  process.exit(1);
});
