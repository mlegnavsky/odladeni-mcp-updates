'use strict';
const fs=require('node:fs');
function confinedStatus(status,uid,baselineFilters=0){
 const values=Object.fromEntries(status.split('\n').map(line=>{const at=line.indexOf(':');return [line.slice(0,at),line.slice(at+1).trim()];}));
 const ids=(values.Uid||'').split(/\s+/).map(Number);
 return uid>0&&ids.length===4&&ids.every(id=>id===uid)&&Number(values.Seccomp_filters)>baselineFilters&&values.Seccomp==='2'&&values.NoNewPrivs==='1'&&/^0+$/.test(values.CapEff||'');
}
function webContentCommand(command){const args=command.split('\0').filter(Boolean);return command.includes('-contentproc')&&/(?:\x00|\s)tab(?:\x00|\s|$)/.test(command);}
function verifyContentSandbox(){
 const content=[];const seen=new Set();const baseline=Number(fs.readFileSync('/proc/self/status','utf8').match(/^Seccomp_filters:\s*(\d+)/m)?.[1]??NaN);
 if(!Number.isFinite(baseline))throw new Error('Baseline sandbox filters cannot be read');
 function children(pid,depth=0){
  if(depth>6||seen.has(pid))return;seen.add(pid);
  let list;try{list=fs.readFileSync(`/proc/${pid}/task/${pid}/children`,'utf8').trim().split(/\s+/).filter(Boolean);}catch{return;}
  for(const child of list){
   try{const command=fs.readFileSync(`/proc/${child}/cmdline`,'utf8');if(webContentCommand(command))content.push(fs.readFileSync(`/proc/${child}/status`,'utf8'));}catch{}
   children(child,depth+1);
  }
 }
 children(process.pid);
 if(!content.length||!content.every(s=>confinedStatus(s,process.getuid(),baseline))){const e=new Error('Browser content sandbox could not be verified');e.code=content.length?'SANDBOX_CONTENT_UNCONFINED':'SANDBOX_CONTENT_NOT_FOUND';throw e;}
 return {sandbox:true,sandbox_mode:'seccomp',content_processes:content.length};
}
module.exports={confinedStatus,verifyContentSandbox,webContentCommand};
