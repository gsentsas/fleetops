const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const ts=require('../r71-mobile-typecheck/node_modules/typescript');
const path=require('node:path');
function compile(p) {
  return ts.transpileModule(fs.readFileSync(p,'utf8'),{
    fileName:p,compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2020}
  }).outputText;
}
let count=0;
function check(ok,label) {
  assert(ok,label);count++;
  console.log('R74_HANDOFF_'+label+'=PASS');
}
const src=compile(path.join(__dirname,'src/hooks/use-fleetbase.ts'));
function runCase(enabled,status,driverToken){
  const instantiated=[];
  let configCalls=[];
  const secure={credential:'SYNTHETIC_DEVICE_SCOPE_KEY_123456',host:'https://secure.invalid'};
  const react={
    useState(initial){const value=typeof initial==='function'?initial():initial;return [value,()=>{}];},
    useEffect(fn){fn();},
    useMemo(fn){return fn();},
    useCallback(fn){return fn;}
  };
  class Fleetbase {
    constructor(key,options){instantiated.push({key,host:options.host})}
    getAdapter(){return {}}
  }
  const modules={
    react, '@fleetbase/sdk':{__esModule:true,default:Fleetbase},
    '../contexts/ConfigContext':{useConfig(){return {resolveConnectionConfig(k){
      configCalls.push(k);
      return k==='FLEETBASE_KEY'?'LEGACY_MMKV_KEY':'https://legacy.invalid';
    }}}},
    './use-storage':{__esModule:true,default(){return [driverToken]}},
    '../services/navigator-v2-release-gate':{NAVIGATOR_V2_SDK_ENABLED:enabled},
    './use-navigator-v2-device':{useNavigatorV2Device(flag){
      check(flag===enabled,'GATE_PROPAGATED_'+status+'_'+String(driverToken!==null));
      return status==='ready'?{status:'ready',credential:secure}:{status,credential:null};
    }}
  };
  const sandbox={module:{exports:{}},exports:{},require(name){
    assert(modules[name],name);return modules[name];
  }};
  sandbox.exports=sandbox.module.exports;
  vm.runInNewContext(src,sandbox);
  const result=sandbox.module.exports.default();
  return {instantiated, configCalls, result};
}
const legacy=runCase(false,'off',null);
check(legacy.instantiated.every(x=>x.key==='LEGACY_MMKV_KEY'),'LEGACY_UNCHANGED_WHEN_GATE_OFF');
const absent=runCase(true,'absent',null);
check(absent.instantiated.length===0&&!absent.result.hasFleetbaseConfig(),'NO_FALLBACK_WHEN_V2_ABSENT');
const invalid=runCase(true,'invalid',null);
check(invalid.instantiated.length===0&&!invalid.result.hasFleetbaseConfig(),'NO_FALLBACK_WHEN_KEYCHAIN_INVALID');
const ready=runCase(true,'ready',null);
check(ready.instantiated.length===1 && ready.instantiated[0].key==='SYNTHETIC_DEVICE_SCOPE_KEY_123456' &&
 ready.instantiated[0].host==='https://secure.invalid','SECURE_DEVICE_KEY_ONLY');
const driver=runCase(true,'ready','SYNTHETIC_DRIVER_TOKEN');
check(driver.instantiated.length===1 && driver.instantiated[0].key==='SYNTHETIC_DRIVER_TOKEN' &&
 driver.instantiated[0].host==='https://secure.invalid','DRIVER_TOKEN_PRESERVES_SECURE_HOST');
check(ready.configCalls.length===0,'READY_V2_AVOIDS_LEGACY_CONFIG');
console.log('R74_FLEETBASE_SDK_GATE_CHECKS='+count+'/'+count);
