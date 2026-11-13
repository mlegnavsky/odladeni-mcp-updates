'use strict';
const fs=require('node:fs');
function confinedStatus(status,uid,baselineFilters=0){
 const values=Object.fromEntries(status.split('\n').map(line=>{const at=line.indexOf(':');return [line.slice(0,at),line.slice(at+1).trim()];}));
 const ids=(values.Uid||'').split(/\s+/).map(Number);
 return uid>0&&ids.length===4&&ids.every(id=>id===uid)&&Number(values.Seccomp_filters)>baselineFilters&&values.Seccomp==='2'&&values.NoNewPrivs==='1'&&/^0+$/.test(values.CapEff||'');
}
function webContentCommand(command){const args=command.split('\0').filter(Boolean);return command.includes('-contentproc')&&/(?:\x00|\s)tab(?:\x00|\s|$)/.test(command);}
function descendantProcesses(root,proc=fs){
 const output=[],seen=new Set();
 function walk(pid,depth=0){if(depth>8||seen.has(String(pid)))return;seen.add(String(pid));let tasks;try{tasks=proc.readdirSync(`/proc/${pid}/task`);}catch{return;}
  for(const task of tasks){let children;try{children=proc.readFileSync(`/proc/${pid}/task/${task}/children`,'utf8').trim().split(/\s+/).filter(Boolean);}catch{continue;}
   for(const child of children){if(!/^\d+$/.test(child)||seen.has(child))continue;output.push(child);walk(child,depth+1);}
  }
 }
 walk(root);return [...new Set(output)];
}
function verifyContentSandbox(){
 const content=[];const baseline=Number(fs.readFileSync('/proc/self/status','utf8').match(/^Seccomp_filters:\s*(\d+)/m)?.[1]??NaN);
 if(!Number.isFinite(baseline))throw new Error('Baseline sandbox filters cannot be read');
 for(const pid of descendantProcesses(process.pid))try{const command=fs.readFileSync(`/proc/${pid}/cmdline`,'utf8');if(webContentCommand(command))content.push(fs.readFileSync(`/proc/${pid}/status`,'utf8'));}catch{}
 if(!content.length||!content.every(s=>confinedStatus(s,process.getuid(),baseline))){const e=new Error('Browser content sandbox could not be verified');e.code=content.length?'SANDBOX_CONTENT_UNCONFINED':'SANDBOX_CONTENT_NOT_FOUND';throw e;}
 return {sandbox:true,sandbox_mode:'seccomp',content_processes:content.length};
}
module.exports={confinedStatus,verifyContentSandbox,webContentCommand,descendantProcesses};
