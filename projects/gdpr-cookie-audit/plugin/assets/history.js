(function(root){
'use strict';
const key='gca-history-v1',valid=i=>i&&/^[a-f0-9]{32}$/.test(i.id)&&/^[a-f0-9]{48}$/.test(i.token)&&typeof i.url==='string'&&/^https?:\/\//.test(i.url)&&i.url.length<=2048;
function read(storage){try{const r=JSON.parse(storage.getItem(key)||'[]');return Array.isArray(r)?r.filter(valid).slice(0,100):[];}catch(e){return [];}}
function remember(storage,item){try{if(!valid(item))return false;const {id,token,url,started_at,report_url}=item;storage.setItem(key,JSON.stringify([{id,token,url,started_at,...(typeof report_url==='string'&&sharedKey(new URL(report_url).search)?{report_url}:{})},...read(storage).filter(i=>i.id!==id)].slice(0,100)));return true;}catch(e){return false;}}
function fragment(i){return '#gca='+i.id+'.'+i.token;}
function parse(hash){const v=new URLSearchParams(hash.replace(/^#/,'' )).get('gca')||'';const m=/^([a-f0-9]{32})\.([a-f0-9]{48})$/.exec(v);return m?{id:m[1],token:m[2]}:null;}
function sharedKey(search){const key=new URLSearchParams(search).get('mereni');return /^[a-f0-9]{64}$/.test(key||'')?key:null;}
function href(item){try{const key=sharedKey(new URL(item.report_url).search);if(key)return '?mereni='+key;}catch(e){}return fragment(item);}
function address(item,persisted){const link=new URL(item.report_url);return link.pathname+link.search+(item.id&&item.token&&!persisted?fragment(item):'');}
root.GCAHistory={read,remember,fragment,parse,sharedKey,href,address};
})(typeof window!=='undefined'?window:globalThis);
