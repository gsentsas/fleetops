const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const ts=require('../r71-mobile-typecheck/node_modules/typescript');
const filename=__dirname+'/src/services/navigator-v2-claim-to-vault.ts';
const source=fs.readFileSync(filename,'utf8');
const compiled=ts.transpileModule(source,{
  fileName:filename,compilerOptions:{target:ts.ScriptTarget.ES2020,module:ts.ModuleKind.CommonJS}
}).outputText;

const secureHost='https://api.synthetic.invalid';
const socketHost='socket.synthetic.invalid';
const session={
  pairingSessionId:'a'.repeat(64),
  publicKeyFingerprint:'b'.repeat(64),
  expiresUnix:Math.floor(Date.now()/1000)+180,
  companyUuid:'company-B',
  challenge:'c'.repeat(64),
  challengeExpiresUnix:Math.floor(Date.now()/1000)+120
};
const sample={
  credential:'SYNTHETIC_NAVIGATOR_SCOPED_KEY_1234567',
  host:secureHost,
  socketcluster_host:socketHost,
  socketcluster_port:8000,
  socketcluster_secure:true
};
let gates=0;
function pass(ok,name){
  assert(ok,name);gates++;
  console.log('R75_VAULT_'+name+'=PASS');
}
async function runScenario(settings={}){
 const state={claim:0,load:0,save:0,purge:0,vault:settings.existing?sample:null};
 const client={async claimAfterAdminApproval(){
   state.claim++;
   if(settings.claimFails)throw new Error('synthetic_server_denied');
   return sample;
 }};
 const release={NAVIGATOR_V2_SDK_ENABLED:settings.gate!==false};
 const vault={
   async loadNavigatorV2Credential(host,socket){
     assert.equal(host,secureHost);assert.equal(socket,socketHost);
     state.load++;
     if(settings.preReadFails && state.load===1)throw new Error('synthetic_read_fail');
     if(settings.postReadFails && state.load>1)throw new Error('synthetic_read_fail');
     return state.vault;
   },
   async saveNavigatorV2Credential(value,host,socket){
     assert.equal(value,sample);
     assert.equal(host,secureHost);assert.equal(socket,socketHost);
     state.save++;
     if(settings.saveFails)throw new Error('synthetic_hardware_error');
     state.vault=value;
   },
 };
 const migration={async purgeLegacyKeysAfterVerifiedV2Pairing(host,socket){
   assert.equal(host,secureHost);assert.equal(socket,socketHost);
   state.purge++;
   if(settings.purgeFails)throw new Error('synthetic_storage_cleanup_error');
 }};
 const modules={
   './navigator-v2-release-gate':release,
   './navigator-v2-secure-credentials':vault,
   './navigator-v2-migration':migration
 };
 const mod={exports:{}};
 vm.runInNewContext(compiled,{
   module:mod,exports:mod.exports,
   require(name){assert(modules[name],name);return modules[name];}
 },{filename:'claim-to-vault.js'});
 let result,error;
 try{
   result=await mod.exports.claimNavigatorV2ToSecureVault(
     client,settings.expired?{...session,expiresUnix:1}:session,
     secureHost,socketHost
   );
 }catch(e){error=e;}
 return {state,result,error};
}
(async()=>{
  let s=await runScenario({gate:false});
  pass(!!s.error&&s.state.claim===0&&s.state.load===0,'OFF_FLAG_NO_SIDE_EFFECTS');
  s=await runScenario({expired:true});
  pass(!!s.error&&s.state.claim===0,'EXPIRED_SESSION_NO_CLAIM');
  s=await runScenario({existing:true});
  pass(!!s.error&&s.state.claim===0&&s.state.save===0,'EXISTING_VAULT_NO_OVERWRITE');
  s=await runScenario({preReadFails:true});
  pass(!!s.error&&s.state.claim===0,'UNREADABLE_VAULT_NO_CLAIM');
  s=await runScenario({claimFails:true});
  pass(!!s.error&&s.state.claim===1&&s.state.save===0&&s.state.purge===0,'SERVER_DENIAL_NO_STORAGE');
  s=await runScenario();
  pass(!s.error&&s.result.status==='ready'&&s.result.deviceConfirmationCode==='b'.repeat(12),'SUCCESS_RETURNS_PUBLIC_CODE_ONLY');
  pass(s.state.claim===1&&s.state.save===1&&s.state.load===2&&s.state.purge===1,'SUCCESS_WRITE_READBACK_THEN_PURGE');
  s=await runScenario({saveFails:true});
  pass(!!s.error&&s.state.claim===1&&s.state.purge===0,'HARDWARE_FAILURE_NO_LEGACY_PURGE');
  pass(s.error.message.includes('revocation required')&&!s.error.message.includes(sample.credential),'HARDWARE_FAILURE_REQUIRES_ADMIN_REVOKE');
  s=await runScenario({postReadFails:true});
  pass(!!s.error&&s.state.claim===1&&s.state.save===1&&s.state.purge===0,'READBACK_FAILURE_NO_LEGACY_PURGE');
  s=await runScenario({purgeFails:true});
  pass(!!s.error&&s.state.claim===1&&s.state.save===1&&s.state.purge===1,'CLEANUP_FAILURE_DOES_NOT_RECLAIM');
  pass(!source.includes('console.log')&&!source.includes('setInstanceLinkConfig')&&!source.includes('useStorage('),'NO_LEGACY_STORE_OR_CONSOLE_LOG');
  console.log('R75_CLAIM_TO_VAULT_CONTRACT='+gates+'/'+gates);
})().catch(e=>{console.error('R75_VAULT_TEST_FAIL',e.message);process.exit(1)});
