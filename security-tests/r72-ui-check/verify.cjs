const fs=require('fs');
const assert=require('assert/strict');
const babel=require('@babel/parser');
const {preprocess}=require('@glimmer/syntax');
const root='/opt/gst-rc1-staging/s98-r62-fleetops-v2';
const js=fs.readFileSync(root+'/addon/components/navigator-pairing-approvals.js','utf8');
const hbs=fs.readFileSync(root+'/addon/components/navigator-pairing-approvals.hbs','utf8');
const screen=fs.readFileSync(root+'/addon/templates/settings/navigator-app.hbs','utf8');
babel.parse(js,{sourceType:'module',plugins:['decorators-legacy','classProperties']});
console.log('R72_EMBER_JS_PARSE=PASS');
preprocess(hbs);
console.log('R72_GLIMMER_HBS_PARSE=PASS');
preprocess(screen);
console.log('R72_SETTINGS_HBS_PARSE=PASS');
const cases={
 'ON_SETTINGS_PAGE':screen.includes('<NavigatorPairingApprovals />'),
 'ISSUER_LIST_POST':js.includes("this.fetch.post('fleet-ops/navigator/pairings/admin-pending'"),
 'APPROVE_POST':js.includes("this.fetch.post('fleet-ops/navigator/pairings/approve'"),
 'FINGERPRINT_TYPED':js.includes('deviceConfirmationCode: this.confirmation'),
 'PROTECTED_ABILITY':js.includes("fleet-ops update navigator-settings"),
 'MANUAL_CONFIRM':hbs.includes('Approuver après comparaison') && hbs.includes('typeConfirmation'),
 'NO_CREDENTIAL_UI':!hbs.includes('pairingSecret') && !hbs.includes('credential'),
 'NO_QR_URI_SUBMISSION':!js.includes('linkUrl') && !hbs.includes('?key=')
};
for(const [key,value] of Object.entries(cases)) {
 console.log('R72_EMBER_STATIC_'+key+'='+(value?'PASS':'FAIL'));
 assert(value,key);
}
console.log('R72_EMBER_UI_SECURITY=8/8');
