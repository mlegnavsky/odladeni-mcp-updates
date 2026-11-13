'use strict';
const fs=require('node:fs');
function armDeadline(ms,onTimeout){
 const group=Number(fs.readFileSync('/proc/self/stat','utf8').split(') ')[1].split(' ')[2]);
 if(group!==process.pid)throw new Error('Worker must own its process group');
 const timer=setTimeout(()=>{try{onTimeout();}finally{process.kill(-process.pid,'SIGKILL');}},ms);
 return ()=>clearTimeout(timer);
}
module.exports={armDeadline};
