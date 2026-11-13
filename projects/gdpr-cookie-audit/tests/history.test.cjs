const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
const c={URL,URLSearchParams};vm.createContext(c);
const source=__dirname+'/../plugin/assets/history.js';if(fs.existsSync(source))vm.runInContext(fs.readFileSync(source,'utf8'),c);
const item={id:'a'.repeat(32),token:'b'.repeat(48),url:'https://spd.cz/',started_at:'2026-11-13T16:00:00Z'};
test('private report fragment round trips without query string tokens',()=>{assert.ok(c.GCAHistory,'history helper exists');const f=c.GCAHistory.fragment(item);assert.ok(f.startsWith('#gca='));const r=c.GCAHistory.parse(f);assert.equal(r.id,item.id);assert.equal(r.token,item.token);assert.equal(c.GCAHistory.parse('#gca=invalid'),null);});
test('history stores only bounded reference metadata and tolerates blocked storage',()=>{assert.ok(c.GCAHistory);let raw;const store={getItem:()=>raw,setItem:(k,v)=>{raw=v;}};c.GCAHistory.remember(store,{...item,report:{secret:'notstored'}});assert.equal(c.GCAHistory.read(store).length,1);assert.ok(!raw.includes('secret'));c.GCAHistory.remember(store,{...item,url:'https://example.com/'});assert.equal(c.GCAHistory.read(store).length,1);assert.equal(c.GCAHistory.read({getItem(){throw Error('denied')}}).length,0);assert.equal(c.GCAHistory.remember({getItem(){return null},setItem(){throw Error('quota')}},item),false);});
test('canonical report address uses one opaque query hash and legacy references still work',()=>{
 const report_url='https://gdpr-cookies.cz/kontrola-cookies/?mereni='+'c'.repeat(64);
 assert.equal(typeof c.GCAHistory.href,'function');assert.equal(c.GCAHistory.href({...item,report_url}),'?mereni='+'c'.repeat(64));assert.equal(c.GCAHistory.href(item),c.GCAHistory.fragment(item));
 assert.equal(c.GCAHistory.sharedKey('?mereni='+'c'.repeat(64)),'c'.repeat(64));assert.equal(c.GCAHistory.sharedKey('?mereni=wrong'),null);
 let raw;const store={getItem:()=>raw,setItem:(k,v)=>raw=v};c.GCAHistory.remember(store,{...item,report_url});assert.equal(c.GCAHistory.read(store)[0].report_url,report_url);
});
test('owner resume survives reload when history persistence is unavailable',()=>{
 const report_url='https://gdpr-cookies.cz/kontrola-cookies/?mereni='+'c'.repeat(64);assert.equal(typeof c.GCAHistory.address,'function');
 assert.equal(c.GCAHistory.address({...item,report_url},false),'/kontrola-cookies/?mereni='+'c'.repeat(64)+c.GCAHistory.fragment(item));
 assert.equal(c.GCAHistory.address({...item,report_url},true),'/kontrola-cookies/?mereni='+'c'.repeat(64));
 assert.equal(c.GCAHistory.address({report_url},false),'/kontrola-cookies/?mereni='+'c'.repeat(64));
});
