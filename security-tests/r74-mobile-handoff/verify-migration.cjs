const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const ts=require('../r71-mobile-typecheck/node_modules/typescript');
const source=fs.readFileSync(__dirname+'/src/services/navigator-v2-migration.ts','utf8');
const code=ts.transpileModule(source,{
  fileName:'navigator-v2-migration.ts',
  compilerOptions:{target:ts.ScriptTarget.ES2020,module:ts.ModuleKind.CommonJS}
}).outputText;
const deletes=[];
let current=null;
let readFails=false;
let clearFails=false;
const storage={removeItem(k){deletes.push(k)}};
const vault={
  async loadNavigatorV2Credential(host,socket){
    assert.equal(host,'https://trusted.invalid');
    assert.equal(socket,'wss.trusted.invalid');
    if(readFails)throw new Error('synthetic_corruption');
    return current;
  },
  async clearNavigatorV2Credential(){
    if(clearFails)throw new Error('synthetic_clear_fail');
    current=null;
  }
};
const mod={exports:{}};
vm.runInNewContext(code,{module:mod,exports:mod.exports,require(x){
  if(x==='../hooks/use-storage')return {storage};
  if(x==='./navigator-v2-secure-credentials')return vault;
  throw new Error('unexpected import');
}});
const {purgeLegacyKeysAfterVerifiedV2Pairing,clearNavigatorLocalPairing}=mod.exports;
const args=['https://trusted.invalid','wss.trusted.invalid'];
let count=0;
function check(ok,key){assert(ok,key);count++;console.log('R74_MIGRATION_'+key+'=PASS')}
(async()=>{
  await assert.rejects(purgeLegacyKeysAfterVerifiedV2Pairing(...args));
  check(deletes.length===0,'NO_VAULT_NO_LEGACY_ERASE');
  readFails=true;
  await assert.rejects(purgeLegacyKeysAfterVerifiedV2Pairing(...args));
  readFails=false;
  check(deletes.length===0,'CORRUPTED_VAULT_PRESERVES_LEGACY');
  current={credential:'SYNTHETIC_NEW_SCOPED_KEY'};
  await purgeLegacyKeysAfterVerifiedV2Pairing(...args);
  check(deletes.length===2,'ONLY_AFTER_VAULT_READBACK');
  check(deletes.includes('INSTANCE_LINK_FLEETBASE_KEY'),'MMKV_INSTANCE_KEY_CLEARED');
  check(deletes.includes('_FLEETBASE_KEY'),'MMKV_OLD_KEY_CLEARED');
  deletes.length=0;
  clearFails=true;
  await assert.rejects(clearNavigatorLocalPairing());
  clearFails=false;
  check(deletes.length===0,'VAULT_WIPE_FAILURE_PRESERVES_STATE');
  await clearNavigatorLocalPairing();
  check(deletes.length===2 && current===null,'LOCAL_UNLINK_WIPES_BOTH');
  check(!source.includes('saveNavigatorV2Credential('),'NO_AUTOMATIC_LEGACY_TO_SCOPED_PROMOTION');
  console.log('R74_SAFE_MIGRATION_CHECKS='+count+'/'+count);
})().catch((e)=>{console.error('R74_MIGRATION_TEST_FAIL',e.message);process.exit(1)});
