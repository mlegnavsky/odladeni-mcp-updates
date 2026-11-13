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
test('automatic documentation findings preserve outcome, source and specific recommendation',()=>{
 const r=model.summarize({url:'https://example.com/',pages:[],policies:[{url:'https://example.com/cookies/',checks:{retention:{label:'Platnost',status:'warning',outcome:'missing',source_url:null,evidence:'Nenalezeno ve dvou dokumentech.',recommendation:'Doplňte životnost každé cookie.'},withdrawal:{label:'Odvolání',status:'pass',outcome:'found',source_url:'https://example.com/tabulka-cookies/',evidence:'Změňte souhlas v nastavení.'}}}]});
 assert.equal(r.findings.find(f=>f.id==='policy-0-retention').fix,'Doplňte životnost každé cookie.');
 assert.equal(r.findings.find(f=>f.id==='policy-0-withdrawal').url,'https://example.com/tabulka-cookies/');
 assert.equal(model.policyOutcomeLabel({outcome:'missing'}),'Chybí v dostupné dokumentaci');
 assert.equal(model.policyOutcomeLabel({outcome:'conflict'}),'Rozporná formulace');
});
function scoredReport(overrides={}){
 const checks=Object.fromEntries(['purposes','retention','withdrawal','choice','inventory','implied_consent'].map(id=>[id,{label:id,status:'pass',outcome:id==='implied_consent'?'not_detected':'found'}]));
 return {state:'complete',url:'https://example.com/',pages:[],policies:[{url:'https://example.com/cookies/',checks:{...checks,...overrides},services:[],cookies:[]}],errors:[],browser:{available:false}};
}
test('documentation percent uses explicit checks and does not claim browser compliance',()=>{
 const r=scoredReport({retention:{status:'warning',outcome:'missing'},withdrawal:{status:'warning',outcome:'missing'},inventory:{status:'warning',outcome:'missing'},implied_consent:{status:'warning',outcome:'conflict'}});
 const score=model.summarize(r).documentationScore;
 assert.equal(score.percent,33);assert.equal(score.passed,2);assert.equal(score.total,6);assert.equal(score.scope,'documentation');
});
test('unavailable document data never becomes zero or a completed percentage',()=>{
 const score=model.summarize(scoredReport({retention:{status:'unknown',outcome:'unavailable'}})).documentationScore;
 assert.equal(score.percent,null);assert.equal(score.unavailable,1);
 assert.equal(model.summarize({state:'running',pages:[],policies:[],errors:[]}).documentationScore.percent,null);
});
test('missing observed services reduce document score and remain explicit criteria',()=>{
 const r=scoredReport();r.policies[0].services=[{id:'meta',name:'Meta',status:'warning'}];
 const score=model.summarize(r).documentationScore;
 assert.equal(score.percent,86);assert.equal(score.total,7);assert.equal(score.criteria.at(-1).status,'warning');
 assert.equal(model.policyOutcomeLabel({outcome:'missing'}),'Chybí v dostupné dokumentaci');
});
test('document availability suppresses percent even if all check outcomes are otherwise known',()=>{
 const r=scoredReport({implied_consent:{status:'error',outcome:'conflict'}});
 r.policies[0].documents=[{url:'https://example.com/cookies/',available:true},{url:'https://example.com/table/',available:false}];
 assert.equal(model.summarize(r).documentationScore.percent,null);
 r.policies[0].documents[1]={url:'https://example.com/table/',available:true,text_truncated:true};
 assert.equal(model.summarize(r).documentationScore.percent,null);
});
test('rounded percentages never claim full completion when one criterion failed',()=>{
 const r=scoredReport();r.policies[0].cookies=Array.from({length:200},(_,i)=>({name:'cookie_'+i,status:i===199?'error':'pass'}));
 const score=model.summarize(r).documentationScore;
 assert.equal(score.passed,205);assert.equal(score.total,206);assert.equal(score.percent,99);
});
test('declared categories have explicit metadata percentages and a weighted overview',()=>{
 const records=[{name:'_ga',domain:'.example.com',category:'analytics',lifetime:'1 rok',description:'Měření návštěvnosti.',basis:'declaration'},{name:'unknown',domain:'.example.com',category:'other',lifetime:'6 měsíců',description:'Description is currently not available.',basis:'declaration'}];
 const s=model.summarize({state:'complete',pages:[],policies:[],declared_cookies:records});
 assert.equal(s.declarationAssessment.percent,80);assert.equal(s.declarationAssessment.passed,8);assert.equal(s.declarationAssessment.total,10);
 assert.equal(s.declarationAssessment.categories.find(c=>c.id==='analytics').percent,100);
 assert.equal(s.declarationAssessment.categories.find(c=>c.id==='other').percent,60);
 assert.equal(s.declarationAssessment.categories.find(c=>c.id==='necessary').percent,null);
 assert.equal(s.cookies.length,0);assert.equal(s.declaredCookies.length,2);
});
test('failed declaration source and dynamic document input suppress completed percentages',()=>{
 const r=scoredReport();r.policies[0].documents=[{url:'https://example.com/cookies/',available:true,dynamic_content_available:false}];r.declaration_errors=[{message:'timeout'}];
 assert.equal(model.summarize(r).documentationScore.percent,null);
 assert.equal(model.summarize(r).declarationAssessment.percent,null);
});

test('missing cookie document in a completed bounded crawl scores zero with a concrete issue',()=>{
 const r={state:'complete',url:'https://spd.cz/',pages:[{url:'https://spd.cz/',services:[],cookies:[]}],policies:[],errors:[],policy_discovery:{status:'missing',issues:[{url:'https://spd.cz/',label:'Pravidla Cookies',href:'#'}]}};
 const s=model.summarize(r);assert.equal(s.documentationScore.percent,0);assert.equal(s.overviewScore.percent,0);assert.equal(s.findings.find(f=>f.id==='policy-missing').status,'error');assert.ok(s.findings.some(f=>f.id.startsWith('policy-link-')));
 r.policy_discovery.status='unavailable';assert.equal(model.summarize(r).documentationScore.percent,null);
 r.state='running';r.policy_discovery.status='pending';assert.equal(model.summarize(r).documentationScore.percent,null);
});
test('failed browser run cannot claim a completed100 percent technical score',()=>{const s=model.summarize({pages:[],policies:[],browser:{status:'failed',reason:'HTTP403',results:[{id:'before',status:'pass'},{id:'reject',status:'unknown'}]}});assert.equal(s.browserScore.percent,null);assert.equal(s.browserScore.unavailable,1);});
