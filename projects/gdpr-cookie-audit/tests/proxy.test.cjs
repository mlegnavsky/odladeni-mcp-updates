const test=require('node:test'),assert=require('node:assert/strict'),http=require('node:http');
const {isPublicIP,resolvePublic,createPinnedProxy}=require('../plugin/runtime/proxy.cjs');
test('public CDN IPv4/IPv6 are allowed; special-purpose addresses are denied',()=>{
 for(const ip of ['104.21.40.189','172.67.156.78','2606:4700::1111','2a06:98c0::1','2400:cb00::1','8.8.8.8'])assert.equal(isPublicIP(ip),true,ip);
 for(const ip of ['127.0.0.1','10.1.2.3','169.254.169.254','192.168.1.1','100.64.0.1','203.0.113.1','::1','::ffff:127.0.0.1','fc00::1','2001:db8::1','3fff::1','2002:7f00:1::1'])assert.equal(isPublicIP(ip),false,ip);
});
test('mixed public/private DNS answer is rejected; approved address is pinned',async()=>{
 await assert.rejects(()=>resolvePublic('mixed.example',async()=>[{address:'8.8.8.8',family:4},{address:'10.0.0.1',family:4}]),/private|neveřej/i);
 const answer=await resolvePublic('public.example',async()=>[{address:'2606:4700::1111',family:6},{address:'8.8.8.8',family:4}]);
 assert.equal(answer.address,'8.8.8.8');
});
test('proxy rejects direct loopback and nonstandard CONNECT ports',async()=>{
 const proxy=await createPinnedProxy();
 async function connect(target){return new Promise((resolve,reject)=>{const req=http.request({host:'127.0.0.1',port:proxy.port,method:'CONNECT',path:target});req.on('connect',(res,socket)=>{socket.destroy();resolve(res.statusCode);});req.on('error',reject);req.end();});}
 try{assert.equal(await connect('127.0.0.1:443'),403);assert.equal(await connect('example.com:22'),403);}finally{await proxy.close();}
});
