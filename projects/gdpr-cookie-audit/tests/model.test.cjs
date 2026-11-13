const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const sandbox={};vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(path.join(__dirname,'../plugin/assets/model.js'),'utf8'),sandbox);
const model=sandbox.GCAModel;
test('source evidence is not counted as a consent violation',()=>{
 const s=model.summarize({state:'complete',pages:[{url:'https://example.com/',services:[{id:'meta',name:'Meta',category:'reklama'}],cookies:[],policies:[]}],policies:[],errors:[]});
 assert.equal(s.errors,0);assert.equal(s.services.length,1);assert.equal(s.unknown,1);
 assert.ok(s.findings.some(f=>f.id==='policy-missing' && f.status==='warning'));
});
test('measured consent outcomes replace placeholders',()=>{
 const s=model.summarize({url:'https://example.com/',pages:[],policies:[],errors:[],browser:{results:[{id:'reject',status:'pass',title:'Odmítnutí',evidence:'Kliknuto a nebyla vytvořena analytická cookie.'},{id:'before',status:'error',title:'Před souhlasem',evidence:'Vytvořena _ga před volbou.'}]}});
 assert.equal(s.scenarios.length,2);assert.equal(s.unknown,0);assert.equal(s.errors,1);
 assert.ok(s.findings.some(f=>f.id==='dynamic-reject'&&f.status==='pass'));
});
test('general privacy findings are excluded even from older report data',()=>{
 const s=model.summarize({pages:[],policies:[{url:'https://example.com/cookies',checks:{contact:{label:'Provozovatel a kontakt',status:'warning'},purposes:{label:'Účely cookies',status:'pass',evidence:'Analytika'}}}],errors:[]});
 assert.ok(!s.findings.some(x=>x.title.includes('Provozovatel')));
 assert.equal(s.cookiePolicies[0].checks.contact,undefined);
});
test('partial failures survive into report and CSV formulas are escaped',()=>{
 const s=model.summarize({state:'partial',pages:[],policies:[],errors:[{url:'https://x.cz/',message:'timeout'}]});
 assert.equal(s.errors,1);assert.equal(model.csvCell('=CMD()'),'"\'=CMD()"');
});
test('cookie metadata is deduplicated by name domain and path',()=>{
 const cookie={name:'x',domain:'example.com',path:'/'};
 const s=model.summarize({pages:[{url:'https://example.com/',services:[],cookies:[cookie,cookie]}],policies:[],errors:[]});
 assert.equal(s.cookies.length,1);
});
test('external documentation integrations do not become audited-site trackers',()=>{
 const s=model.summarize({pages:[{url:'https://example.com/',is_target:true,services:[],cookies:[]},{url:'https://external.com/privacy',is_target:false,services:[{id:'meta'}],cookies:[{name:'external',domain:'external.com'}]}],policies:[],errors:[]});
 assert.equal(s.services.length,0);assert.equal(s.cookies.length,0);
});
