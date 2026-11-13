'use strict';
const http=require('node:http'),net=require('node:net'),dns=require('node:dns').promises;
const deny4=new net.BlockList(),deny6=new net.BlockList(),allow6=new net.BlockList();
for(const [ip,bits] of [['0.0.0.0',8],['10.0.0.0',8],['100.64.0.0',10],['127.0.0.0',8],['169.254.0.0',16],['172.16.0.0',12],['192.0.0.0',24],['192.0.2.0',24],['192.88.99.0',24],['192.168.0.0',16],['198.18.0.0',15],['198.51.100.0',24],['203.0.113.0',24],['224.0.0.0',4],['240.0.0.0',4]])deny4.addSubnet(ip,bits,'ipv4');
allow6.addSubnet('2000::',3,'ipv6');
for(const [ip,bits] of [['2001::',23],['2001:db8::',32],['2002::',16],['3fff::',20]])deny6.addSubnet(ip,bits,'ipv6');
function isPublicIP(ip){const family=net.isIP(ip);return family===4?!deny4.check(ip,'ipv4'):family===6&&allow6.check(ip,'ipv6')&&!deny6.check(ip,'ipv6');}
async function resolvePublic(host,lookup=(name)=>dns.lookup(name,{all:true,verbatim:true})){
 host=host.replace(/^\[|\]$/g,'');
 const answers=net.isIP(host)?[{address:host,family:net.isIP(host)}]:await lookup(host);
 if(!answers.length||answers.some(a=>!isPublicIP(a.address)))throw new Error('Private/neveřejná síť is not permitted');
 return answers.find(a=>a.family===4)||answers[0];
}
async function createPinnedProxy(){
 const sockets=new Set(),cache=new Map();let transferred=0,closed=false;
 async function approved(host){if(!cache.has(host))cache.set(host,resolvePublic(host));return cache.get(host);}
 function watch(socket){sockets.add(socket);socket.setTimeout(15000,()=>socket.destroy());socket.on('close',()=>sockets.delete(socket));socket.on('error',()=>{});socket.on('data',buf=>{transferred+=buf.length;if(transferred>50*1024*1024)for(const s of sockets)s.destroy();});return socket;}
 function fail(socket,code=403){if(!socket.destroyed)socket.end('HTTP/1.1 '+code+' Forbidden\r\nConnection: close\r\nContent-Length: 0\r\n\r\n');}
 const server=http.createServer(async(req,res)=>{
  let target;
  try{target=new URL(req.url);if(target.protocol!=='http:'||target.username||target.password||(target.port&&target.port!=='80'))throw new Error('Invalid URL');}catch{res.writeHead(403);return res.end();}
  try{
   const address=await approved(target.hostname);if(closed)throw new Error('closed');
   const headers={...req.headers,host:target.host};delete headers['proxy-authorization'];delete headers['proxy-connection'];
   const upstream=http.request({hostname:address.address,port:80,method:req.method,path:target.pathname+target.search,headers,timeout:12000},response=>{res.writeHead(response.statusCode,response.headers);response.on('data',b=>{transferred+=b.length;if(transferred>50*1024*1024)response.destroy();});response.pipe(res);});
   upstream.on('timeout',()=>upstream.destroy());upstream.on('error',()=>{if(!res.headersSent)res.writeHead(502);res.end();});
   let bytes=0;req.on('data',b=>{bytes+=b.length;if(bytes>2*1024*1024){req.destroy();upstream.destroy();}});req.pipe(upstream);
  }catch{res.writeHead(403);res.end();}
 });
 server.on('connection',socket=>{watch(socket);if(sockets.size>100)socket.destroy();});
 server.on('connect',async(req,client,head)=>{
  try{
   const authority=new URL('https://'+req.url);
   if(authority.username||authority.password||(authority.port&&authority.port!=='443')||authority.pathname!=='/'||authority.search||authority.hash)throw new Error('Forbidden authority');
   const answer=await approved(authority.hostname);if(closed||client.destroyed)return;
   const upstream=watch(net.connect({host:answer.address,port:443}));
   upstream.once('connect',()=>{if(client.destroyed){upstream.destroy();return;}client.write('HTTP/1.1 200 Connection Established\r\n\r\n');if(head.length)upstream.write(head);upstream.pipe(client);client.pipe(upstream);});
   upstream.on('error',()=>fail(client,502));client.on('close',()=>upstream.destroy());upstream.on('close',()=>client.destroy());
  }catch{fail(client);}
 });
 server.on('clientError',(_,socket)=>fail(socket,400));
 await new Promise((resolve,reject)=>{server.once('error',reject);server.listen(0,'127.0.0.1',resolve);});
 return {port:server.address().port,close:()=>new Promise(resolve=>{closed=true;for(const socket of sockets)socket.destroy();server.close(resolve);})};
}
module.exports={isPublicIP,resolvePublic,createPinnedProxy};
