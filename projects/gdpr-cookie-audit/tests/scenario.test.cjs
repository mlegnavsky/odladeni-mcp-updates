const test=require('node:test'),assert=require('node:assert/strict');
const {cookieCategory,cookieMetadata,evaluateScenario}=require('../plugin/runtime/scenario-logic.cjs');
test('cookie values are discarded and unknown purpose is not invented',()=>{
 const c=cookieMetadata({name:'unknown-cookie',value:'SECRET',domain:'.example.com',path:'/',expires:-1,secure:true,httpOnly:false,sameSite:'Lax'},'https://example.com/');
 assert.ok(!JSON.stringify(c).includes('SECRET'));assert.equal(c.category,'unknown');assert.equal(cookieCategory('_ga'),'analytics');assert.equal(cookieCategory('cookieyes-consent'),'necessary');
});
test('failed interaction never produces a passing rejection result',()=>{
 assert.equal(evaluateScenario('reject',{action:false,cookies:[]}).status,'unknown');
 assert.equal(evaluateScenario('reject',{action:true,cookies:[{name:'_ga',category:'analytics'}]}).status,'error');
 assert.equal(evaluateScenario('reject',{action:true,cookies:[]}).status,'pass');
});
test('remaining historical cookie after withdrawal is a warning, not proof of fresh tracking',()=>{
 assert.equal(evaluateScenario('withdraw',{action:true,consentChanged:true,cookies:[{name:'_ga',category:'analytics'}]}).status,'warning');
});
