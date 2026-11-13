(function(root){
'use strict';
const key='gca-history-v1',valid=i=>i&&/^[a-f0-9]{32}$/.test(i.id)&&/^[a-f0-9]{48}$/.test(i.token)&&typeof i.url==='string'&&/^https?:\/\//.test(i.url)&&i.url.length<=2048;
function read(storage){try{const r=JSON.parse(storage.getItem(key)||'[]');return Array.isArray(r)?r.filter(valid).slice(0,100):[];}catch(e){return [];}}
function remember(storage,item){try{if(!valid(item))return false;const {id,token,url,started_at}=item;storage.setItem(key,JSON.stringify([{id,token,url,started_at},...read(storage).filter(i=>i.id!==id)].slice(0,100)));return true;}catch(e){return false;}}
function fragment(i){return '#gca='+i.id+'.'+i.token;}
function parse(hash){const v=new URLSearchParams(hash.replace(/^#/,'' )).get('gca')||'';const m=/^([a-f0-9]{32})\.([a-f0-9]{48})$/.exec(v);return m?{id:m[1],token:m[2]}:null;}
root.GCAHistory={read,remember,fragment,parse};
})(typeof window!=='undefined'?window:globalThis);
