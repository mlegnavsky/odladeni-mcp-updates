const test=require('node:test'),assert=require('node:assert/strict');
const {platformKey,dependencyPackages}=require('../plugin/runtime/dependencies.cjs');
test('uses the hosting distribution and only validated browser library packages',()=>{
 assert.equal(platformKey('ID=ubuntu\nVERSION_ID="22.04"\n'),'ubuntu22.04-x64');
 assert.deepEqual(dependencyPackages({'ubuntu22.04-x64':{chromium:['libnspr4','libnss3','fonts-liberation']}},'ubuntu22.04-x64'),['libnspr4','libnss3']);
 assert.throws(()=>dependencyPackages({'ubuntu22.04-x64':{chromium:['libnspr4;evil']}},'ubuntu22.04-x64'),/package/i);
 assert.throws(()=>platformKey('ID=unknown\nVERSION_ID=1'),/unsupported/i);
});
